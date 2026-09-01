<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Rule;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\SettlementEvent;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\SettlementRule;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettlementRule::class)]
final class SettlementRuleTest extends TestCase
{
    private const ACC1 = AssessmentStream::ACC1;
    private const ACC2 = AssessmentStream::ACC2;

    private Ledger $ledger;
    private HoldRegistry $holds;
    private SettlementRule $rule;

    protected function setUp(): void
    {
        $this->ledger = new Ledger(
            Account::emptyIn(self::ACC1, Currency::AED),
            Account::emptyIn(self::ACC2, Currency::BHD),
        );
        $this->holds = new HoldRegistry();
        $this->rule = new SettlementRule($this->ledger, $this->holds);
    }

    private static function acc(string $id = self::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }

    private function settlement(
        string $id,
        string $auth,
        string $amount,
        int $day = 4,
        string $account = self::ACC1,
        ?int $valueDate = null,
    ): SettlementEvent {
        return new SettlementEvent(
            EventId::of($id),
            self::acc($account),
            AuthorizationId::of($auth),
            self::aed($amount),
            self::day($day),
            self::day($valueDate ?? $day),
        );
    }

    private function balanceAsOf(int $valueDate, int $knownAsOf): string
    {
        return $this->ledger->balanceAsOf(self::acc(), self::day($valueDate), self::day($knownAsOf))->format();
    }

    /** E1, E2, E3 and E4 — the account stands at 650.00 on Day 3 with Auth-A holding 200.00. */
    private function replayThroughDayThree(): void
    {
        $this->ledger->append(LedgerEntry::credit(self::acc(), self::aed('1200.00'), self::day(1), self::day(1), 'E1'));
        $this->ledger->append(LedgerEntry::debit(self::acc(), self::aed('950.00'), self::day(1), self::day(1), 'E2'));
        $this->holds->place(Hold::place(
            AuthorizationId::of('Auth-A'), self::acc(), self::aed('200.00'), self::day(2),
        ));
        $this->ledger->append(LedgerEntry::credit(self::acc(), self::aed('400.00'), self::day(3), self::day(3), 'E4'));
    }

    private function balanceOn(int $day): string
    {
        return $this->ledger->balanceAsOf(self::acc(), self::day($day), self::day($day))->format();
    }

    // ==================================================== E5 — the accepted settlement

    /**
     * ACCEPTANCE CRITERION 3, accepted. "The Day 4 settlement of Auth-A must be accepted."
     *
     * 185.00 settles inside a live 200.00 hold on the account that placed it. There is no
     * ground to refuse it, and the criterion says so correctly.
     */
    public function testCriterionThreeAuthAsSettlementIsAccepted(): void
    {
        $this->replayThroughDayThree();

        $decision = $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'));

        self::assertSame(EventOutcome::POSTED, $decision->outcome);
        self::assertSame('465.00', $this->balanceOn(4), '650.00 less the 185.00 settled');
    }

    public function testSettlingReleasesTheHold(): void
    {
        $this->replayThroughDayThree();

        $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'));

        $hold = $this->holds->find(AuthorizationId::of('Auth-A'));
        self::assertFalse($hold->isActive());
        self::assertSame(4, $hold->releasedOn?->number);
        self::assertSame('0.00', $this->holds->totalHeldFor($this->ledger->account(self::acc()))->format());
    }

    /**
     * The entry and the release happen together or not at all. Split across call sites, an
     * account pays twice — once in the balance, once in a hold nobody let go of.
     */
    public function testTheEntryAndTheReleaseAreOneOperation(): void
    {
        $this->replayThroughDayThree();
        $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'));

        self::assertSame(4, $this->ledger->count(), 'E1, E2, E4 and the settlement');
        self::assertSame('E5', $this->ledger->entries()[3]->reference);
        self::assertSame('-185.00', $this->ledger->entries()[3]->amount->format(), 'stored signed');
        self::assertSame([], $this->holds->activeFor(self::acc()));
    }

    /** A settlement closes the whole hold even when it settles for less than was reserved. */
    public function testSettlingForLessThanWasHeldReturnsTheDifference(): void
    {
        $this->replayThroughDayThree();

        $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'));

        self::assertSame('0.00', $this->holds->totalHeldFor($this->ledger->account(self::acc()))->format(),
            'not 15.00 left reserved');
    }

    /** The hold is an estimate; the settled figure is what the network guarantees. */
    public function testSettlingForMoreThanWasHeldPostsTheSettledFigure(): void
    {
        $this->replayThroughDayThree();

        $decision = $this->rule->apply($this->settlement('E5', 'Auth-A', '250.00'));

        self::assertSame(EventOutcome::POSTED, $decision->outcome);
        self::assertSame('400.00', $this->balanceOn(4));
    }

    /**
     * The two dates are carried separately, which E5 alone cannot prove — it is processed on
     * Day 4 with value_date Day 4, so a rule that used the wrong one would look identical.
     *
     * A settlement presented on Day 5 for value_date Day 3 must land in Day 3's balance and be
     * invisible to anyone who asked on Day 4. No such event is in the stream; the rule is
     * written to the ledger's bitemporal contract regardless, and this is what says so.
     */
    public function testTheSettlementCarriesItsOwnValueDateNotTheDayItWasProcessed(): void
    {
        $this->replayThroughDayThree();

        $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00', day: 5, valueDate: 3));

        $entry = $this->ledger->entries()[3];
        self::assertSame(3, $entry->valueDate->number, 'the day the money belongs to');
        self::assertSame(5, $entry->bookedDay->number, 'the day the ledger learned of it');

        self::assertSame('465.00', $this->balanceAsOf(3, 5), 'Day 3 restated once it is known');
        self::assertSame('650.00', $this->balanceAsOf(3, 4), 'and untouched for anyone asking on Day 4');
    }

    public function testASameDaySettlementCarriesThatDayInBothCoordinates(): void
    {
        $this->replayThroughDayThree();
        $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'));

        $entry = $this->ledger->entries()[3];
        self::assertSame(4, $entry->valueDate->number);
        self::assertSame(4, $entry->bookedDay->number);
    }

    public function testThePostingExplainsItself(): void
    {
        $this->replayThroughDayThree();

        self::assertSame(
            'Settled Auth-A for 185.00 at value_date Day 4; the 200.00 hold is released.',
            $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'))->reason,
        );
    }

    // ==================================================== E6 — the orphan

    /**
     * ACCEPTANCE CRITERION 4, accepted. "Any settlement referencing an authorization ID not
     * present in the ledger must be rejected and the funds must not leave the account."
     *
     * Honoured literally. In a card network this is the wrong answer — an unmatched settlement
     * is a force-post, the money has already moved, and refusing it creates a reconciliation
     * break rather than saving 180.00. The brief's criterion outranks industry practice, and
     * AMBIGUITIES.md §2 carries the figures force-posting would have produced (final 210.69).
     */
    public function testCriterionFourAnOrphanSettlementIsRejectedAndTheFundsStay(): void
    {
        $this->replayThroughDayThree();
        $before = $this->balanceOn(4);

        $decision = $this->rule->apply($this->settlement('E6', 'Auth-Z', '180.00'));

        self::assertSame(EventOutcome::REJECTED_ORPHAN_SETTLEMENT, $decision->outcome);
        self::assertSame($before, $this->balanceOn(4), 'the funds did not leave the account');
        self::assertSame(3, $this->ledger->count(), 'and nothing was appended');
    }

    public function testTheOrphanRejectionNamesTheAuthorizationAndTheAccount(): void
    {
        self::assertSame(
            'No authorization "Auth-Z" was ever issued on ACC-001. Nothing is posted and the '
            . 'funds stay in the account.',
            $this->rule->apply($this->settlement('E6', 'Auth-Z', '180.00'))->reason,
        );
    }

    /** A rejection is never silence. E6 posts nothing, so the log is its only trace. */
    public function testTheOrphanIsRecordedRatherThanDropped(): void
    {
        $log = new DecisionLog();
        $log->record($this->rule->apply($this->settlement('E6', 'Auth-Z', '180.00')));

        self::assertCount(1, $log->rejections());
        self::assertSame('E6', $log->rejections()[0]->event->value);
        self::assertTrue($log->rejections()[0]->outcome->isError());
    }

    /** A declined authorization reserved nothing, so settling it is orphaned, not "already settled". */
    public function testSettlingAnAuthorizationThatWasDeclinedIsAnOrphan(): void
    {
        $this->replayThroughDayThree();

        $decision = $this->rule->apply($this->settlement('E11', 'Auth-B', '90.00', day: 5));

        self::assertSame(EventOutcome::REJECTED_ORPHAN_SETTLEMENT, $decision->outcome);
    }

    // ==================================================== states the stream never reaches

    public function testAnAuthorizationCannotBeSettledTwice(): void
    {
        $this->replayThroughDayThree();
        $this->rule->apply($this->settlement('E5', 'Auth-A', '185.00'));
        $countAfterFirst = $this->ledger->count();

        $decision = $this->rule->apply($this->settlement('E11', 'Auth-A', '185.00', day: 5));

        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $decision->outcome);
        self::assertStringContainsString('already settled on Day 4', $decision->reason);
        self::assertSame($countAfterFirst, $this->ledger->count(), 'nothing posted a second time');
    }

    public function testASettlementCannotDrawOnAnotherAccountsHold(): void
    {
        $this->replayThroughDayThree();

        $decision = $this->rule->apply($this->settlement('E11', 'Auth-A', '185.00', account: self::ACC2));

        self::assertSame(EventOutcome::REJECTED_INVALID_EVENT, $decision->outcome);
        self::assertStringContainsString('held against ACC-001, not ACC-002', $decision->reason);
        self::assertSame(3, $this->ledger->count());
    }

    // ==================================================== every path is logged

    /**
     * The rule returns a Decision on every path — posted, orphaned, or refused against the
     * current state. There is no branch that returns nothing, which is what makes "every event
     * is accounted for" a property of the type rather than a discipline the caller has to keep.
     */
    public function testEveryOutcomeIsADecisionFitForTheLog(): void
    {
        $this->replayThroughDayThree();
        $log = new DecisionLog();

        $log->record($this->rule->apply($this->settlement('E5', 'Auth-A', '185.00')));
        $log->record($this->rule->apply($this->settlement('E6', 'Auth-Z', '180.00')));
        $log->record($this->rule->apply($this->settlement('E11', 'Auth-A', '10.00', day: 5)));

        self::assertSame(
            ['POSTED', 'REJECTED_ORPHAN_SETTLEMENT', 'REJECTED_INVALID_EVENT'],
            array_map(static fn (Decision $d): string => $d->outcome->value, $log->all()),
        );
        foreach ($log->all() as $decision) {
            self::assertNotSame('', $decision->reason, 'every decision explains itself');
        }
    }
}
