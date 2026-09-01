<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Event;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\EventType;
use Ledger\Domain\Event\Exception\UnexplainedDecision;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecisionLog::class)]
#[CoversClass(Decision::class)]
final class DecisionLogTest extends TestCase
{
    /** @var array<string, LedgerEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        foreach (AssessmentStream::asListed()->asListed() as $event) {
            $this->events[$event->id->value] = $event;
        }
    }

    private function event(string $id): LedgerEvent
    {
        return $this->events[$id];
    }

    private function decision(string $id, EventOutcome $outcome, string $reason = 'because'): Decision
    {
        return Decision::about($this->event($id), $outcome, $reason);
    }

    // ==================================================== recording

    public function testADecisionTakesItsContextFromTheEventItIsAbout(): void
    {
        $decision = $this->decision('E7', EventOutcome::POSTED, 'posted 620.00 to Day 2');

        self::assertSame('E7', $decision->event->value);
        self::assertSame(EventType::DEBIT, $decision->eventType);
        self::assertSame(AssessmentStream::ACC1, $decision->account->value);
        self::assertSame(5, $decision->day->number, 'the day it was processed, not its value date');
        self::assertSame(EventOutcome::POSTED, $decision->outcome);
        self::assertSame('posted 620.00 to Day 2', $decision->reason);
    }

    public function testDecisionsAreKeptInTheOrderTheyWereRecorded(): void
    {
        $log = new DecisionLog();
        $log->record($this->decision('E1', EventOutcome::POSTED));
        $log->record($this->decision('E3', EventOutcome::APPROVED));
        $log->record($this->decision('E6', EventOutcome::REJECTED_ORPHAN_SETTLEMENT));

        self::assertSame(
            ['E1', 'E3', 'E6'],
            array_map(static fn (Decision $d): string => $d->event->value, $log->all()),
        );
        self::assertSame(3, $log->count());
    }

    public function testAnEmptyLogHasNothingToSay(): void
    {
        $log = new DecisionLog();

        self::assertSame([], $log->all());
        self::assertSame([], $log->rejections());
        self::assertNull($log->about(EventId::of('E1')));
        self::assertSame(0, $log->count());
    }

    // ==================================================== nothing goes unexplained

    /**
     * The log exists so that no event is silently dropped. A decision without a reason is
     * exactly that failure wearing a record's clothes — it says an event was refused and
     * gives no way to find out why. Refused at construction, for every outcome.
     *
     * @return iterable<string, array{EventOutcome}>
     */
    public static function everyOutcome(): iterable
    {
        foreach (EventOutcome::cases() as $outcome) {
            yield strtolower($outcome->value) => [$outcome];
        }
    }

    #[DataProvider('everyOutcome')]
    public function testRefusesADecisionWithNoReason(EventOutcome $outcome): void
    {
        $this->expectException(UnexplainedDecision::class);

        $this->decision('E6', $outcome, '   ');
    }

    public function testTrimsTheReasonItIsGiven(): void
    {
        self::assertSame('funds stay', $this->decision('E6', EventOutcome::POSTED, "  funds stay\n")->reason);
    }

    // ==================================================== reading it back

    private function populated(): DecisionLog
    {
        $log = new DecisionLog();
        $log->record($this->decision('E1', EventOutcome::POSTED, 'credited 1200.00'));
        $log->record($this->decision('E2', EventOutcome::POSTED, 'debited 950.00'));
        $log->record($this->decision('E3', EventOutcome::APPROVED, 'available 250.00 leaves 50.00'));
        $log->record($this->decision('E5', EventOutcome::POSTED, 'settled Auth-A'));
        $log->record($this->decision(
            'E6',
            EventOutcome::REJECTED_ORPHAN_SETTLEMENT,
            'Auth-Z was never authorized; the funds stay',
        ));
        $log->record($this->decision('E7', EventOutcome::POSTED, 'debited 620.00 at value_date Day 2'));
        $log->record($this->decision('E8', EventOutcome::DECLINED, 'available -155.00 leaves -245.00'));
        $log->record($this->decision('E10', EventOutcome::POSTED, 'credited 10.000 in three instalments'));

        return $log;
    }

    public function testFindsTheDecisionAboutOneEvent(): void
    {
        self::assertSame(
            'Auth-Z was never authorized; the funds stay',
            $this->populated()->about(EventId::of('E6'))?->reason,
        );
        self::assertNull($this->populated()->about(EventId::of('E9')), 'never processed');
    }

    /**
     * The two events that post nothing at all. Without the log they would leave no trace, and
     * "the funds must not leave the account" would be indistinguishable from the engine having
     * quietly ignored the event.
     */
    public function testTheRejectionAndTheDeclineAreBothOnTheRecord(): void
    {
        $log = $this->populated();

        self::assertSame(
            ['E6'],
            array_map(static fn (Decision $d): string => $d->event->value, $log->rejections()),
        );
        self::assertSame(
            ['E8'],
            array_map(
                static fn (Decision $d): string => $d->event->value,
                $log->withOutcome(EventOutcome::DECLINED),
            ),
            'a decline is recorded but is not a rejection',
        );
    }

    public function testGroupsDecisionsByTheDayTheyWereMade(): void
    {
        $log = $this->populated();

        self::assertCount(2, $log->onDay(LedgerDay::of(1)));   // E1, E2
        self::assertCount(1, $log->onDay(LedgerDay::of(2)));   // E3
        self::assertCount(2, $log->onDay(LedgerDay::of(4)));   // E5, E6
        self::assertCount(3, $log->onDay(LedgerDay::of(5)));   // E7, E8, E10
        self::assertCount(0, $log->onDay(LedgerDay::of(6)));
    }

    /**
     * A decision is filed under the day it was made, not the day the money belongs to. E7 is
     * processed on Day 5 carrying value_date Day 2: the entry it posts lands in Day 2's
     * balance, but the decision to post it belongs to Day 5's report, because Day 5 is when
     * anyone could have seen it happen.
     */
    public function testABackdatedEventIsFiledUnderTheDayItWasProcessed(): void
    {
        $log = $this->populated();

        self::assertSame(
            ['E7', 'E8', 'E10'],
            array_map(static fn (Decision $d): string => $d->event->value, $log->onDay(LedgerDay::of(5))),
        );
        self::assertSame(
            ['E3'],
            array_map(static fn (Decision $d): string => $d->event->value, $log->onDay(LedgerDay::of(2))),
            'E7 is not filed under Day 2 even though its entry lands there',
        );
    }

    public function testSeparatesTheTwoAccounts(): void
    {
        $log = $this->populated();

        self::assertCount(7, $log->forAccount(AccountId::of(AssessmentStream::ACC1)));
        self::assertSame(
            ['E10'],
            array_map(
                static fn (Decision $d): string => $d->event->value,
                $log->forAccount(AccountId::of(AssessmentStream::ACC2)),
            ),
        );
    }

    public function testCountsByOutcome(): void
    {
        self::assertCount(5, $this->populated()->withOutcome(EventOutcome::POSTED));
        self::assertCount(1, $this->populated()->withOutcome(EventOutcome::APPROVED));
        self::assertCount(0, $this->populated()->withOutcome(EventOutcome::REJECTED_DUPLICATE_EVENT_ID));
    }

    /**
     * Once duplicate rejection exists, one id can carry two decisions: the first posted, the
     * second was refused. about() returns the first, which is the one that changed anything.
     */
    public function testWhenOneIdIsSeenTwiceTheFirstDecisionIsTheOneReturned(): void
    {
        $log = new DecisionLog();
        $log->record($this->decision('E7', EventOutcome::POSTED, 'posted 620.00'));
        $log->record($this->decision('E7', EventOutcome::REJECTED_DUPLICATE_EVENT_ID, 'already seen'));

        self::assertSame(EventOutcome::POSTED, $log->about(EventId::of('E7'))?->outcome);
        self::assertSame(2, $log->count(), 'and both are kept');
    }

    public function testRendersItselfForTheReport(): void
    {
        self::assertSame(
            'E6  Day 4  REJECTED_ORPHAN_SETTLEMENT  Auth-Z was never authorized; the funds stay',
            $this->decision(
                'E6',
                EventOutcome::REJECTED_ORPHAN_SETTLEMENT,
                'Auth-Z was never authorized; the funds stay',
            )->describe(),
        );
    }

    // ==================================================== append-only

    /**
     * The same structural guarantee the Ledger carries, and for the same reason: a decision
     * that could be edited after the fact is not a record of what happened.
     */
    public function testExposesNoWayToRemoveOrAlterADecision(): void
    {
        $mutators = array_filter(
            (new \ReflectionClass(DecisionLog::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => (bool) preg_match(
                '/^(remove|delete|replace|update|set|clear|reset|pop|shift|splice|sort)/i',
                $m->getName(),
            ),
        );

        self::assertSame([], array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $mutators));
        self::assertTrue((new \ReflectionClass(Decision::class))->isReadOnly());
    }
}
