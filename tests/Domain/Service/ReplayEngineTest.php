<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Service;

use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Application\LedgerKernel;
use Ledger\Domain\Service\ReplayEngine;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReplayEngine::class)]
final class ReplayEngineTest extends TestCase
{
    private Ledger $ledger;
    private DecisionLog $log;
    private ReplayEngine $engine;

    protected function setUp(): void
    {
        $kernel = LedgerKernel::build(...AssessmentScenarioSource::accounts());

        $this->ledger = $kernel->ledger;
        $this->log = $kernel->log;
        $this->engine = $kernel->engine;
    }

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function credit(string $id, string $account, string $amount, Currency $currency, int $day = 1): CreditEvent
    {
        return new CreditEvent(
            EventId::of($id),
            AccountId::of($account),
            Money::of($amount, $currency),
            self::day($day),
            self::day($day),
        );
    }

    // ==================================================== every event is accounted for

    /**
     * A malformed event is something to report, not something to die on.
     *
     * Without the engine catching domain exceptions, a currency mismatch would throw out of
     * Ledger::append, abort the replay, and leave *no* record of the event that caused it —
     * an engine that logs everything it expected and nothing that mattered.
     */
    public function testAnEventTheRulesThrowOnBecomesALoggedRejection(): void
    {
        $report = $this->engine->replay(new EventStream(
            self::credit('E1', AssessmentScenarioSource::ACC1, '100.00', Currency::AED),
            // BHD into an AED account — Ledger::append refuses it
            self::credit('E2', AssessmentScenarioSource::ACC1, '100.000', Currency::BHD),
            self::credit('E3', AssessmentScenarioSource::ACC1, '50.00', Currency::AED),
        ));

        self::assertSame(3, $this->log->count(), 'the replay carried on');
        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $this->log->about(EventId::of('E2'))?->outcome);
        self::assertStringContainsString('ACC-001', $this->log->about(EventId::of('E2'))?->reason ?? '');
        // 100.00 + 50.00 — the BHD event posted nothing — plus one day's interest on 150.00.
        // The window is a single day here, because every event in this stream is dated Day 1.
        self::assertSame('0.06', $report->interestFor(AccountId::of(AssessmentScenarioSource::ACC1))->format());
        self::assertSame('150.06', $report->closingBalanceFor(AccountId::of(AssessmentScenarioSource::ACC1))->format());
    }

    public function testAnEventForAnAccountTheLedgerDoesNotHoldIsRejected(): void
    {
        $this->engine->replay(new EventStream(self::credit('E1', 'ACC-999', '100.00', Currency::AED)));

        $decision = $this->log->about(EventId::of('E1'));
        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $decision?->outcome);
        self::assertStringContainsString('holds no account "ACC-999"', $decision?->reason ?? '');
        self::assertSame(0, $this->ledger->count());
    }

    /**
     * The guarantee the DecisionLog exists for, stated as a property rather than checked case
     * by case: however many events go in, exactly that many decisions come out.
     */
    public function testEveryEventProducesExactlyOneDecisionWhateverHappensToIt(): void
    {
        $stream = new EventStream(
            self::credit('E1', AssessmentScenarioSource::ACC1, '100.00', Currency::AED),
            self::credit('E2', 'ACC-999', '100.00', Currency::AED),                     // unknown account
            self::credit('E3', AssessmentScenarioSource::ACC1, '100.000', Currency::BHD), // wrong currency
            self::credit('E1', AssessmentScenarioSource::ACC1, '100.00', Currency::AED),  // duplicate id
            new ReversalEvent(EventId::of('E5'), AccountId::of(AssessmentScenarioSource::ACC1), EventId::of('E404'), self::day(1), self::day(1)),
        );

        $this->engine->replay($stream);

        self::assertSame(count($stream), $this->log->count());
        foreach ($this->log->all() as $decision) {
            self::assertNotSame('', $decision->reason, 'and each one explains itself');
        }
    }

    // ==================================================== the report is a view, not a recount

    /**
     * The interest the report prints must be the interest the ledger holds.
     *
     * This is the assertion that was missing, and its absence hid a real bug: the report used to
     * recompute the total from InterestSchedule *after* capitalization had already appended the
     * credit, so it re-read a final-day balance that now included it. A credit of 37.49 posted
     * 0.01 and reported 0.02 — a closing balance of 37.50 alongside components summing to 37.51,
     * and the brief's "dailies must sum exactly to the capitalized total" broken on the face of
     * the output.
     *
     * The old test asserted that InterestSchedule's total equalled the sum of its own accruals,
     * which is how it is implemented — X == X, unfalsifiable. This compares two independently
     * produced numbers instead.
     *
     * The amounts are chosen so the pre- and post-capitalization balances round differently:
     * 37.49 x 0.04% = 0.014996 -> 0.01, but 37.50 x 0.04% = 0.015 -> 0.02 under HALF_UP.
     *
     * @return iterable<string, array{string}>
     */
    public static function amountsWhereCapitalizationCouldShiftTheRounding(): iterable
    {
        yield 'the original failing case' => ['37.49'];
        yield 'just under a tie'          => ['12.49'];
        yield 'just over a tie'           => ['12.51'];
        yield 'a round figure'            => ['1200.00'];
        yield 'earns nothing at all'      => ['1.00'];
    }

    #[DataProvider('amountsWhereCapitalizationCouldShiftTheRounding')]
    public function testTheInterestReportedIsTheInterestPosted(string $amount): void
    {
        $report = $this->engine->replay(new EventStream(
            self::credit('E1', AssessmentScenarioSource::ACC1, $amount, Currency::AED),
        ));

        $account = AccountId::of(AssessmentScenarioSource::ACC1);
        $posted = Money::zero(Currency::AED);
        foreach ($this->ledger->entriesOfType($account, EntryType::INTEREST) as $entry) {
            $posted = $posted->plus($entry->amount);
        }

        self::assertSame(
            $posted->format(),
            $report->interestFor($account)->format(),
            'the report must print what the ledger holds, not a second computation of it',
        );
    }

    /**
     * And the arithmetic the report prints has to close: opening plus every entry equals the
     * closing balance it states. A report whose own components do not sum to its own total is
     * wrong however defensible each figure is in isolation.
     */
    #[DataProvider('amountsWhereCapitalizationCouldShiftTheRounding')]
    public function testTheReportedClosingBalanceIsTheSumOfTheLedgerEntries(string $amount): void
    {
        $report = $this->engine->replay(new EventStream(
            self::credit('E1', AssessmentScenarioSource::ACC1, $amount, Currency::AED),
        ));

        $account = AccountId::of(AssessmentScenarioSource::ACC1);
        $sum = Money::zero(Currency::AED);
        foreach ($this->ledger->entriesFor($account) as $entry) {
            $sum = $sum->plus($entry->amount);
        }

        self::assertSame($sum->format(), $report->closingBalanceFor($account)->format());
        self::assertSame(
            $sum->format(),
            Money::of($amount, Currency::AED)->plus($report->interestFor($account))->format(),
            'credit + interest must equal the closing balance printed',
        );
    }

    // ==================================================== the shape of a day

    /**
     * Events first, then the close. E7 lands on Day 5 and the fees it makes due are assessed
     * the same evening — but *after* Auth-B has already been decided against -155.00, so no
     * ambiguity in the fee reading can reach back and change that decline.
     */
    public function testEventsAreProcessedBeforeTheDayIsClosed(): void
    {
        $this->engine->replay(AssessmentScenarioSource::stream());

        $dayFive = $this->log->onDay(self::day(5));

        self::assertSame(
            ['E7', 'E8', 'E10'],
            array_map(static fn (Decision $d): string => $d->event->value, $dayFive),
        );
        self::assertStringContainsString('-155.00', $this->log->about(EventId::of('E8'))?->reason ?? '');
    }

    /**
     * The overdraft fee is stated in AED and only ACC-001 ever overdraws, so nothing in the
     * brief's stream reaches this. Drive ACC-002 negative and the close must skip it rather
     * than try to post a 25.00 AED fee into a BHD account — which the ledger would refuse,
     * aborting the close for *both* accounts.
     *
     * What a 25.00 AED fee means on a BHD account is undefined by the brief. Skipping is the
     * honest answer; inventing an exchange rate would be a worse one.
     */
    public function testAnAccountInAnotherCurrencyIsSkippedByTheFeeAssessmentRatherThanCrashingIt(): void
    {
        $report = $this->engine->replay(new EventStream(
            self::credit('E1', AssessmentScenarioSource::ACC1, '10.00', Currency::AED, 1),
            new \Ledger\Domain\Event\DebitEvent(
                EventId::of('E2'),
                AccountId::of(AssessmentScenarioSource::ACC2),
                Money::of('5.000', Currency::BHD),
                self::day(1),
                self::day(1),
            ),
        ));

        self::assertSame(EventOutcome::POSTED, $this->log->about(EventId::of('E2'))?->outcome);
        self::assertSame('-5.000', $report->closingBalanceFor(AccountId::of(AssessmentScenarioSource::ACC2))->format(),
            'overdrawn, and charged nothing');
        self::assertSame([], $this->ledger->entriesOfType(
            AccountId::of(AssessmentScenarioSource::ACC2),
            \Ledger\Domain\Ledger\EntryType::OVERDRAFT_FEE,
        ));
        self::assertSame('10.00', $report->closingBalanceFor(AccountId::of(AssessmentScenarioSource::ACC1))->format(),
            'and ACC-001 closed normally rather than being taken down with it');
    }

    public function testAnEmptyStreamReplaysToAnEmptyReport(): void
    {
        $report = $this->engine->replay(new EventStream());

        self::assertSame(0, $this->log->count());
        self::assertSame(0, $this->ledger->count());
        self::assertSame('0.00', $report->closingBalanceFor(AccountId::of(AssessmentScenarioSource::ACC1))->format());
    }

    public function testDaysWithNoEventsStillClose(): void
    {
        // A credit on Day 1 and nothing else: Days 2 to 6 have no events but must still appear.
        $report = $this->engine->replay(new EventStream(
            self::credit('E1', AssessmentScenarioSource::ACC1, '100.00', Currency::AED, 6),
        ));

        self::assertCount(6, $report->forAccount(AccountId::of(AssessmentScenarioSource::ACC1)));
    }
}
