<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Rule;

use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Event\ProcessedEvents;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\DuplicateEventRule;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DuplicateEventRule::class)]
final class DuplicateEventRuleTest extends TestCase
{
    private ProcessedEvents $processed;
    private DuplicateEventRule $rule;

    protected function setUp(): void
    {
        $this->processed = new ProcessedEvents();
        $this->rule = new DuplicateEventRule($this->processed);
    }

    private static function credit(string $id, string $amount = '100.00', int $day = 1): CreditEvent
    {
        return new CreditEvent(
            EventId::of($id),
            AccountId::of(AssessmentStream::ACC1),
            Money::of($amount, Currency::AED),
            LedgerDay::of($day),
            LedgerDay::of($day),
        );
    }

    /** @return list<string> */
    private static function ids(array $events): array
    {
        return array_map(static fn (LedgerEvent $e): string => $e->id->value, $events);
    }

    // ==================================================== the gate

    public function testAFirstSightingIsAdmitted(): void
    {
        self::assertNull($this->rule->admit(self::credit('E1')));
        self::assertTrue($this->processed->hasSeen(EventId::of('E1')));
    }

    public function testASecondSightingIsRefused(): void
    {
        $this->rule->admit(self::credit('E1'));

        $refusal = $this->rule->admit(self::credit('E1'));

        self::assertInstanceOf(Decision::class, $refusal);
        self::assertSame(EventOutcome::REJECTED_DUPLICATE_EVENT_ID, $refusal->outcome);
        self::assertSame('E1', $refusal->event->value);
    }

    public function testTheRefusalSaysWhy(): void
    {
        $this->rule->admit(self::credit('E7'));

        self::assertSame(
            'Event E7 was already processed in this replay; nothing is posted a second time.',
            $this->rule->admit(self::credit('E7'))?->reason,
        );
    }

    public function testDistinctEventsAreAllAdmitted(): void
    {
        self::assertNull($this->rule->admit(self::credit('E1')));
        self::assertNull($this->rule->admit(self::credit('E2')));
        self::assertNull($this->rule->admit(self::credit('E10')));
        self::assertSame(3, $this->processed->count());
    }

    /**
     * The gate reads the id and nothing else, so it cannot tell a benign retry from a
     * same-id-different-payload integrity breach — it absorbs both as duplicates.
     *
     * This test exists to make that limitation executable rather than a claim in a README.
     * Payload hashing would separate the two cases and was dropped as scope creep: the stream
     * contains neither, and nothing here would act on the distinction. If that ever changes,
     * this test is where the change announces itself.
     */
    public function testAnEventReusingAnIdWithADifferentPayloadIsAbsorbedAsADuplicate(): void
    {
        $this->rule->admit(self::credit('E1', '1200.00'));

        $refusal = $this->rule->admit(self::credit('E1', '9999.00'));

        self::assertNotNull($refusal, 'silently swallowed — a known limitation, not a bug');
        self::assertSame(EventOutcome::REJECTED_DUPLICATE_EVENT_ID, $refusal->outcome);
    }

    /** The guard runs on the id, so it does not care which day a repeat arrives on. */
    public function testARepeatIsRefusedWhicheverDayItArrivesOn(): void
    {
        $this->rule->admit(self::credit('E1', '100.00', day: 1));

        self::assertNotNull($this->rule->admit(self::credit('E1', '100.00', day: 6)));
    }

    // ==================================================== the idempotency property

    /**
     * @return array{list<LedgerEvent>, list<Decision>} admitted events, refusals
     */
    private function sift(EventStream $stream): array
    {
        $admitted = [];
        $refused = [];

        foreach ($stream as $event) {
            $refusal = $this->rule->admit($event);
            if ($refusal === null) {
                $admitted[] = $event;
            } else {
                $refused[] = $refusal;
            }
        }

        return [$admitted, $refused];
    }

    /**
     * The property this guard exists for, in the half that is provable without the engine:
     * feed it every event twice and exactly the original ten get through, in the order they
     * would have had anyway.
     *
     * The other half — that the ledger, the balances and the report are byte-identical to a
     * single-emission run — needs the replay engine and is asserted end-to-end at that point.
     * What is settled here is that nothing downstream is ever offered a repeat.
     */
    public function testEveryEventOfferedTwiceAdmitsExactlyTheOriginalTen(): void
    {
        [$admitted, $refused] = $this->sift(AssessmentStream::withDuplicates());

        self::assertCount(20, AssessmentStream::withDuplicates(), 'the doubled stream');
        self::assertSame(
            ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E10', 'E9'],
            self::ids($admitted),
            'the ten originals, still in replay order',
        );
        self::assertCount(10, $refused);
    }

    public function testEveryRefusalIsRecordedAsADuplicateRatherThanSomethingElse(): void
    {
        [, $refused] = $this->sift(AssessmentStream::withDuplicates());

        $outcomes = array_unique(array_map(static fn (Decision $d): string => $d->outcome->value, $refused));

        self::assertSame(['REJECTED_DUPLICATE_EVENT_ID'], array_values($outcomes));
        self::assertSame(
            ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E10', 'E9'],
            array_map(static fn (Decision $d): string => $d->event->value, $refused),
            'each original is refused exactly once on its repeat',
        );
    }

    /**
     * The same property when the whole stream is replayed end to end rather than each event
     * being retried on the spot. The second E1 arrives on Day 1 *after* Day 6 has been
     * processed — and is refused on its id, before anything downstream could refuse it for
     * the wrong reason.
     */
    public function testReplayingTheWholeStreamASecondTimeAdmitsNothing(): void
    {
        [$first] = $this->sift(AssessmentStream::asListed());
        self::assertCount(10, $first);

        [$second, $refused] = $this->sift(AssessmentStream::asListed());

        self::assertSame([], $second, 'a second full replay gets nothing through');
        self::assertCount(10, $refused);
    }

    public function testTheRefusalsAreFitForTheDecisionLog(): void
    {
        $log = new DecisionLog();
        $this->rule->admit(self::credit('E1'));

        $refusal = $this->rule->admit(self::credit('E1'));
        self::assertNotNull($refusal);
        $log->record($refusal);

        self::assertCount(1, $log->withOutcome(EventOutcome::REJECTED_DUPLICATE_EVENT_ID));
        self::assertCount(1, $log->rejections());
    }
}
