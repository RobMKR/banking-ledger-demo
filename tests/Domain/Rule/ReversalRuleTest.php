<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Rule;

use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\ReversalRule;
use Ledger\Tests\Support\AssessmentLedger;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReversalRule::class)]
final class ReversalRuleTest extends TestCase
{
    private Ledger $ledger;
    private ReversalRule $rule;

    protected function setUp(): void
    {
        $this->ledger = new Ledger(
            Account::emptyIn(AssessmentStream::ACC1, Currency::AED),
            Account::emptyIn(AssessmentStream::ACC2, Currency::BHD),
        );
        $this->rule = new ReversalRule($this->ledger);
    }

    private static function acc(string $id = AssessmentStream::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    private static function d(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $a): Money
    {
        return Money::of($a, Currency::AED);
    }

    private function reversal(string $id, string $reverses, int $day, string $account = AssessmentStream::ACC1): ReversalEvent
    {
        return new ReversalEvent(EventId::of($id), self::acc($account), EventId::of($reverses), self::d($day), self::d(2));
    }

    /** E1, E2, then E7 — the backdated 620.00 that E9 undoes. */
    private function replayThroughE7(): void
    {
        $this->ledger->append(LedgerEntry::credit(self::acc(), self::aed('1200.00'), self::d(1), self::d(1), 'E1'));
        $this->ledger->append(LedgerEntry::debit(self::acc(), self::aed('950.00'), self::d(1), self::d(1), 'E2'));
        $this->ledger->append(LedgerEntry::debit(self::acc(), self::aed('620.00'), self::d(2), self::d(5), 'E7'));
    }

    private function balance(int $valueDate, int $knownAsOf): string
    {
        return $this->ledger->balanceAsOf(self::acc(), self::d($valueDate), self::d($knownAsOf))->format();
    }

    // ==================================================== E9

    public function testReversingE7RestoresTheBalanceItTookAway(): void
    {
        $this->replayThroughE7();
        self::assertSame('-370.00', $this->balance(2, 5));

        $decision = $this->rule->apply($this->reversal('E9', 'E7', 6));

        self::assertSame(EventOutcome::POSTED, $decision->outcome);
        self::assertSame('250.00', $this->balance(2, 6));
    }

    /**
     * Nothing is deleted. The reversal appends the inverse, so the ledger ends up holding both
     * the 620.00 debit and the 620.00 credit that undoes it — the balance returns while the
     * record only grows.
     */
    public function testTheOriginalEntryStaysInTheLedger(): void
    {
        $this->replayThroughE7();

        $this->rule->apply($this->reversal('E9', 'E7', 6));

        $references = array_map(static fn (LedgerEntry $e): ?string => $e->reference, $this->ledger->entries());
        self::assertSame(['E1', 'E2', 'E7', 'E9'], $references);
        self::assertSame('-620.00', $this->ledger->entryReferencing('E7')?->amount->format());
        self::assertSame('620.00', $this->ledger->entryReferencing('E9')?->amount->format());
    }

    /**
     * The reversal carries the original's value date, not the day it was raised on. Otherwise
     * the two would sit in different days and net to zero on neither. E9 states value_date
     * Day 2, matching E7 — the brief and the rule agree.
     */
    public function testTheReversalTakesTheOriginalsValueDateAndItsOwnBookedDay(): void
    {
        $this->replayThroughE7();

        $this->rule->apply($this->reversal('E9', 'E7', 6));

        $reversal = $this->ledger->entryReferencing('E9');
        self::assertSame(2, $reversal?->valueDate->number, "E7's value date");
        self::assertSame(6, $reversal?->bookedDay->number, 'the day E9 arrived');
        self::assertSame(EntryType::REVERSAL, $reversal?->type);
        self::assertSame('E7', $reversal?->reverses);
    }

    /** Invisible until it arrives: Day 2 still reads -370.00 to anyone asking at Day 5's close. */
    public function testTheReversalIsNotVisibleBeforeItIsBooked(): void
    {
        $this->replayThroughE7();
        $this->rule->apply($this->reversal('E9', 'E7', 6));

        self::assertSame('-370.00', $this->balance(2, 5));
        self::assertSame('250.00', $this->balance(2, 6));
    }

    public function testThePostingExplainsItself(): void
    {
        $this->replayThroughE7();

        self::assertSame(
            'Reversed E7: 620.00 at value_date Day 2. The original entry stands; nothing is deleted.',
            $this->rule->apply($this->reversal('E9', 'E7', 6))->reason,
        );
    }

    // ==================================================== refusals

    public function testThereIsNothingToReverseWhenTheOriginalPostedNoEntry(): void
    {
        $this->replayThroughE7();

        $decision = $this->rule->apply($this->reversal('E9', 'E3', 6));

        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $decision->outcome);
        self::assertStringContainsString('posted no entry', $decision->reason);
        self::assertSame(3, $this->ledger->count());
    }

    /** Reversing twice would credit the account twice over — and could never be undone. */
    public function testAnEntryCannotBeReversedTwice(): void
    {
        $this->replayThroughE7();
        $this->rule->apply($this->reversal('E9', 'E7', 6));

        $decision = $this->rule->apply($this->reversal('E11', 'E7', 6));

        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $decision->outcome);
        self::assertStringContainsString('already been reversed', $decision->reason);
        self::assertSame('250.00', $this->balance(2, 6), 'not 870.00');
    }

    public function testAReversalCannotReachIntoAnotherAccount(): void
    {
        $this->replayThroughE7();

        $decision = $this->rule->apply($this->reversal('E9', 'E7', 6, AssessmentStream::ACC2));

        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $decision->outcome);
        self::assertStringContainsString('posted to ACC-001, not ACC-002', $decision->reason);
    }

    // ==================================================== criterion 6

    /**
     * ACCEPTANCE CRITERION 6, REFUSED.
     *
     * "After E9, all balances and fees return to their pre-E7 values."
     *
     * The refusal does *not* rest on "append-only forbids it" — as the first assertion here
     * shows, an appended reversal restores a balance perfectly well. It rests on the fees. E9
     * reverses E7 and nothing else; no fee-reversal event exists in the stream and no
     * non-negotiable rule grants an auto-reversal, so the three charges stand. Day 2 closes at
     * 225.00, not the 250.00 it held before E7.
     *
     * See REJECTED.md and AMBIGUITIES.md §3.
     */
    public function testCriterionSixBalancesComeBackButTheFeesDoNot(): void
    {
        $beforeE7 = AssessmentLedger::throughDay(4);
        $afterE9 = AssessmentLedger::throughDay(6);

        self::assertSame('250.00', $beforeE7->balanceOn(2, 4), 'Day 2 before E7 ever arrived');
        self::assertSame('225.00', $afterE9->balanceOn(2, 6), 'and after E9 undid it — 25.00 short');

        $fees = $afterE9->ledger->entriesOfType(self::acc(), EntryType::OVERDRAFT_FEE);
        self::assertCount(3, $fees, 'every fee E7 caused is still on the record');
    }
}
