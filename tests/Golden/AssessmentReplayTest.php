<?php

declare(strict_types=1);

namespace Ledger\Tests\Golden;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Money;
use Ledger\Application\LedgerKernel;
use Ledger\Domain\Service\ReplayReport;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource;
use Ledger\Infrastructure\Presenter\JsonPresenter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped configuration, locked end to end.
 *
 * Every figure here is a consequence of four decisions that the spec does not settle, each
 * resolved to one reading and hardcoded: retroactive fee assessment, orphan settlements
 * rejected, fees standing after a reversal, and accruals restated against final knowledge.
 * Change any one and these numbers move — AMBIGUITIES.md carries what each alternative would
 * have produced (390.81 / 466.03 / 210.69 / 441.01).
 *
 * This is deliberately a characterisation test: if it fails, either a bug was introduced or a
 * documented decision was silently changed. Both are things a reader should be told about.
 */
#[CoversNothing]
final class AssessmentReplayTest extends TestCase
{
    private const ACC1 = AssessmentScenarioSource::ACC1;
    private const ACC2 = AssessmentScenarioSource::ACC2;

    private Ledger $ledger;
    private HoldRegistry $holds;
    private DecisionLog $log;

    private function replay(?EventStream $stream = null): ReplayReport
    {
        $kernel = LedgerKernel::build(...AssessmentScenarioSource::accounts());

        $this->ledger = $kernel->ledger;
        $this->holds = $kernel->holds;
        $this->log = $kernel->log;

        return $kernel->replay($stream ?? AssessmentScenarioSource::stream());
    }

    private static function acc(string $id = self::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    // ==================================================== the closing figures

    public function testAccountOneClosesAtThreeNinetyNinetyThree(): void
    {
        $report = $this->replay();

        self::assertSame('390.93', $report->closingBalanceFor(self::acc())->format());
        self::assertSame('0.93', $report->interestFor(self::acc())->format());
    }

    public function testAccountTwoClosesAtTenPointZeroZeroEight(): void
    {
        $report = $this->replay();

        self::assertSame('10.008', $report->closingBalanceFor(self::acc(self::ACC2))->format());
        self::assertSame('0.008', $report->interestFor(self::acc(self::ACC2))->format());
    }

    // ==================================================== the per-day table

    /**
     * Two columns, because "Day 2's closing balance" is not one number.
     *
     * "as known then" is what the day closed at on the evening it closed; "restated" is what it
     * closed at once E9 had arrived. Day 2 is the pair that makes the point: 250.00 and 225.00,
     * both correct, answering different questions — it closed in the black and ends the window
     * 25.00 lighter for a fee assessed three days after it closed.
     *
     * @return iterable<string, array{int, string, string}>
     */
    public static function accountOneByDay(): iterable
    {
        //                        day   as known then   restated
        yield 'D1'            => [1,    '250.00',       '250.00'];
        yield 'D2 restated'   => [2,    '250.00',       '225.00'];
        yield 'D3 restated'   => [3,    '650.00',       '625.00'];
        yield 'D4 restated'   => [4,    '465.00',       '415.00'];
        yield 'D5 restated'   => [5,    '-230.00',      '390.00'];
        yield 'D6'            => [6,    '390.93',       '390.93'];
    }

    #[DataProvider('accountOneByDay')]
    public function testAccountOneDayByDay(int $day, string $asKnownThen, string $restated): void
    {
        $line = $this->replay()->lineFor(self::acc(), LedgerDay::of($day));

        self::assertSame($asKnownThen, $line?->closingAsKnownThen->format());
        self::assertSame($restated, $line?->closingRestated->format());
    }

    /** @return iterable<string, array{int, string}> */
    public static function accountTwoByDay(): iterable
    {
        yield 'D1' => [1, '0.000'];
        yield 'D2' => [2, '0.000'];
        yield 'D3' => [3, '0.000'];
        yield 'D4' => [4, '0.000'];
        yield 'D5' => [5, '10.000'];
        yield 'D6' => [6, '10.008'];
    }

    #[DataProvider('accountTwoByDay')]
    public function testAccountTwoDayByDay(int $day, string $closing): void
    {
        $line = $this->replay()->lineFor(self::acc(self::ACC2), LedgerDay::of($day));

        self::assertSame($closing, $line?->closingRestated->format());
        self::assertFalse($line?->wasRestated(), 'nothing backdated ever touches ACC-002');
    }

    // ==================================================== fees, holds, decisions

    public function testThreeFeesTotallingSeventyFive(): void
    {
        $this->replay();

        $fees = $this->ledger->entriesOfType(self::acc(), EntryType::OVERDRAFT_FEE);

        self::assertSame(
            [2, 4, 5],
            array_map(static fn (LedgerEntry $f): int => $f->valueDate->number, $fees),
        );
        self::assertSame([5, 5, 5], array_map(static fn (LedgerEntry $f): int => $f->bookedDay->number, $fees),
            'all three raised at the close of Day 5, when E7 made them due');
    }

    public function testAllThreeFeesLandOnTheDayFiveLine(): void
    {
        $line = $this->replay()->lineFor(self::acc(), LedgerDay::of(5));

        self::assertCount(3, $line?->fees ?? []);
        self::assertSame('75.00', $line?->feeTotal()->format());
    }

    public function testAuthAIsApprovedAndSettledAndAuthBIsDeclined(): void
    {
        $this->replay();

        self::assertFalse($this->holds->find(AuthorizationId::of('Auth-A'))->isActive(), 'settled on Day 4');
        self::assertFalse($this->holds->has(AuthorizationId::of('Auth-B')), 'declined, so never held');
        self::assertSame('0.00', $this->holds->totalHeldFor($this->ledger->account(self::acc()))->format());
    }

    /** @return iterable<string, array{string, EventOutcome}> */
    public static function everyEventsOutcome(): iterable
    {
        yield 'E1 credit'          => ['E1', EventOutcome::POSTED];
        yield 'E2 debit'           => ['E2', EventOutcome::POSTED];
        yield 'E3 Auth-A'          => ['E3', EventOutcome::APPROVED];
        yield 'E4 credit'          => ['E4', EventOutcome::POSTED];
        yield 'E5 settles Auth-A'  => ['E5', EventOutcome::POSTED];
        yield 'E6 orphan'          => ['E6', EventOutcome::REJECTED_ORPHAN_SETTLEMENT];
        yield 'E7 backdated debit' => ['E7', EventOutcome::POSTED];
        yield 'E8 Auth-B'          => ['E8', EventOutcome::DECLINED];
        yield 'E9 reversal'        => ['E9', EventOutcome::POSTED];
        yield 'E10 instalments'    => ['E10', EventOutcome::POSTED];
    }

    #[DataProvider('everyEventsOutcome')]
    public function testEveryEventsOutcome(string $eventId, EventOutcome $expected): void
    {
        $this->replay();

        self::assertSame(
            $expected,
            $this->log->about(\Ledger\Domain\Event\EventId::of($eventId))?->outcome,
        );
    }

    /**
     * Ten events in, ten decisions out. Nothing is silently dropped, and nothing is recorded
     * twice — the property that makes the log a complete account of the replay rather than a
     * selection from it.
     */
    public function testTenEventsProduceExactlyTenDecisions(): void
    {
        $this->replay();

        self::assertSame(10, $this->log->count());
        self::assertCount(1, $this->log->rejections(), 'only E6');
        self::assertSame('E6', $this->log->rejections()[0]->event->value);
    }

    public function testE10PostsThreeInstalmentsSummingToTenExactly(): void
    {
        $this->replay();

        $entries = $this->ledger->entriesFor(self::acc(self::ACC2));
        $credits = array_values(array_filter($entries, static fn (LedgerEntry $e): bool => $e->type === EntryType::CREDIT));

        self::assertSame(
            ['3.334', '3.333', '3.333'],
            array_map(static fn (LedgerEntry $e): string => $e->amount->format(), $credits),
        );
        self::assertSame(['E10.1', 'E10.2', 'E10.3'], array_map(
            static fn (LedgerEntry $e): ?string => $e->reference,
            $credits,
        ));
    }

    // ==================================================== idempotent replay

    /**
     * Replaying the whole stream twice is a no-op.
     *
     * Every financial figure must be identical to the single-emission run, and the log must
     * carry exactly ten duplicate rejections alongside the ten real decisions. This also
     * cross-checks the "once per day per account" fee guard from the other side: a leak in
     * either would show up as a changed fee total.
     */
    public function testEveryEventOfferedTwiceProducesTheSameFigures(): void
    {
        $once = $this->replay();
        $onceJson = (new JsonPresenter())->present($once);

        $twice = $this->replay(AssessmentScenarioSource::streamWithDuplicates());

        self::assertSame(
            self::financialsOnly($onceJson),
            self::financialsOnly((new JsonPresenter())->present($twice)),
        );
        self::assertSame('390.93', $twice->closingBalanceFor(self::acc())->format());
        self::assertSame('10.008', $twice->closingBalanceFor(self::acc(self::ACC2))->format());
    }

    public function testTheDuplicatesAreAllRecordedAsDuplicates(): void
    {
        $this->replay(AssessmentScenarioSource::streamWithDuplicates());

        self::assertSame(20, $this->log->count(), 'ten real decisions and ten refusals');
        self::assertCount(10, $this->log->withOutcome(EventOutcome::REJECTED_DUPLICATE_EVENT_ID));
        self::assertSame(
            ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E10', 'E9'],
            array_map(
                static fn (Decision $d): string => $d->event->value,
                $this->log->withOutcome(EventOutcome::REJECTED_DUPLICATE_EVENT_ID),
            ),
        );
    }

    public function testTheLedgerDoesNotGrowOnASecondEmission(): void
    {
        $this->replay();
        $entriesOnce = $this->ledger->count();

        $this->replay(AssessmentScenarioSource::streamWithDuplicates());

        self::assertSame($entriesOnce, $this->ledger->count());
        self::assertCount(3, $this->ledger->entriesOfType(self::acc(), EntryType::OVERDRAFT_FEE), 'not six');
        self::assertCount(1, $this->ledger->entriesOfType(self::acc(), EntryType::INTEREST));
    }

    /** Strips the decision narrative, leaving only balances, fees and interest. */
    private static function financialsOnly(string $json): string
    {
        /** @var array{accounts: list<array{days: list<array<string, mixed>>}>} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach ($decoded['accounts'] as &$account) {
            foreach ($account['days'] as &$day) {
                unset($day['events'], $day['errors'], $day['authorizations']);
            }
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }
}
