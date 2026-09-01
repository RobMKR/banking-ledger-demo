<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Rule;

use Ledger\Domain\Event\AuthorizationEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\AvailableBalance;
use Ledger\Domain\Ledger\Exception\AccountCurrencyMismatch;
use Ledger\Domain\Ledger\Exception\DuplicateAuthorization;
use Ledger\Domain\Ledger\Exception\InvalidHold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\AuthorizationVerdict;
use Ledger\Domain\Rule\AuthorizationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationRule::class)]
#[CoversClass(AuthorizationVerdict::class)]
final class AuthorizationRuleTest extends TestCase
{
    private const ACC1 = 'ACC-001';
    private const ACC2 = 'ACC-002';

    private Ledger $ledger;
    private HoldRegistry $holds;
    private AuthorizationRule $rule;

    protected function setUp(): void
    {
        $this->ledger = new Ledger(
            Account::emptyIn(self::ACC1, Currency::AED),
            Account::emptyIn(self::ACC2, Currency::BHD),
        );
        $this->holds = new HoldRegistry();
        $this->rule = new AuthorizationRule(
            $this->ledger,
            $this->holds,
            new AvailableBalance($this->ledger, $this->holds),
        );
    }

    private static function id(string $value = self::ACC1): AccountId
    {
        return AccountId::of($value);
    }

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }

    /** The pure question: would this be approved, and against what balance? Places nothing. */
    private function assess(string $auth, string $amount, int $day): AuthorizationVerdict
    {
        return $this->rule->assess(AuthorizationId::of($auth), self::id(), self::aed($amount), self::day($day));
    }

    // ================================================ the assessment stream, event by event

    /** E1, E2 — Day 1. ACC-001 stands at 250.00. */
    private function replayDayOne(): void
    {
        $this->ledger->append(LedgerEntry::credit(self::id(), self::aed('1200.00'), self::day(1), self::day(1), 'E1'));
        $this->ledger->append(LedgerEntry::debit(self::id(), self::aed('950.00'), self::day(1), self::day(1), 'E2'));
    }

    /**
     * E3 — Day 2. Auth-A asks to hold 200.00.
     *
     * Goes through apply(), not assess(), because the replay has to actually reserve the funds —
     * every later day depends on that 200.00 being live until E5 releases it.
     */
    private function replayAuthA(): Decision
    {
        return $this->applyEvent('E3', 'Auth-A', '200.00', 2);
    }

    /** E4 — Day 3. Credit 400.00. */
    private function replayDayThree(): void
    {
        $this->ledger->append(LedgerEntry::credit(self::id(), self::aed('400.00'), self::day(3), self::day(3), 'E4'));
    }

    /**
     * E5 — Day 4. Auth-A settles for 185.00 and its 200.00 hold is released.
     *
     * E6 belongs to this day too, but under the chosen policy an orphan settlement posts
     * nothing at all (AMBIGUITIES.md §2), so there is nothing here to replay for it.
     */
    private function replayAuthASettlement(): void
    {
        $this->ledger->append(
            LedgerEntry::settlement(self::id(), self::aed('185.00'), self::day(4), self::day(4), 'E5'),
        );
        $this->holds->release(AuthorizationId::of('Auth-A'), self::day(4));
    }

    /** E7 — Day 5. A 620.00 debit backdated to value_date Day 2. */
    private function replayBackdatedDebit(): void
    {
        $this->ledger->append(LedgerEntry::debit(self::id(), self::aed('620.00'), self::day(2), self::day(5), 'E7'));
    }

    /** Everything before E8. */
    private function replayThroughE7(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();
        $this->replayDayThree();
        $this->replayAuthASettlement();
        $this->replayBackdatedDebit();
    }

    // ================================================ Auth-A

    public function testAuthAIsApprovedOnDayTwo(): void
    {
        $this->replayDayOne();

        $verdict = $this->assess('Auth-A', '200.00', 2);

        self::assertTrue($verdict->approved);
        self::assertSame('250.00', $verdict->availableBefore->format());
        self::assertSame('50.00', $verdict->availableAfter->format());
        self::assertSame('APPROVED', $verdict->state());
    }

    public function testAnApprovedAuthorizationReservesTheFunds(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();

        $hold = $this->holds->find(AuthorizationId::of('Auth-A'));
        self::assertTrue($hold->isActive());
        self::assertSame('200.00', $hold->amount->format());
        self::assertSame(2, $hold->placedOn->number);
        self::assertSame('200.00', $this->holds->totalHeldFor($this->ledger->account(self::id()))->format());
    }

    /**
     * assess() answers a question; it does not act on the answer.
     *
     * This is what makes it safe to expose. Reserving funds is apply()'s job alone, and apply()
     * cannot reserve without also returning the Decision that records it — so there is no path
     * through this class that changes an account's available balance without leaving a trace.
     * Were assess() to place the hold, "every reservation is logged" would go back to being a
     * convention every future call site has to remember.
     */
    public function testAssessingAnAuthorizationReservesNothing(): void
    {
        $this->replayDayOne();

        $verdict = $this->assess('Auth-A', '200.00', 2);

        self::assertTrue($verdict->approved, 'it would be approved');
        self::assertFalse($this->holds->has(AuthorizationId::of('Auth-A')), 'but nothing is held');
        self::assertSame('0.00', $this->holds->totalHeldFor($this->ledger->account(self::id()))->format());
        self::assertSame('250.00', $this->assess('Auth-A', '200.00', 2)->availableBefore->format(),
            'and asking again sees the same account');
    }

    /** Asked ten times, the account is exactly as it was. Only apply() moves anything. */
    public function testAssessingIsRepeatableAndApplyIsNot(): void
    {
        $this->replayDayOne();

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($this->assess('Auth-A', '200.00', 2)->approved);
        }
        self::assertSame(0, $this->holds->count());

        $this->replayAuthA();

        self::assertSame(1, $this->holds->count());
        self::assertSame('50.00', $this->assess('Auth-C', '10.00', 2)->availableBefore->format(),
            'now the 200.00 is genuinely reserved');
    }

    /**
     * An authorization moves no money. It is the settlement, two days later, that does — and
     * for Auth-B, which never settles, no money ever moves at all.
     */
    public function testAnAuthorizationWritesNothingToTheLedger(): void
    {
        $this->replayDayOne();
        $before = $this->ledger->count();

        $this->replayAuthA();

        self::assertSame($before, $this->ledger->count());
        self::assertSame(
            '250.00',
            $this->ledger->balanceAsOf(self::id(), self::day(2), self::day(2))->format(),
        );
    }

    // ================================================ Auth-B

    /**
     * E8 — Day 5. The decline the brief is built around.
     *
     * Available is -155.00 before the hold is even considered: E7's backdated 620.00 was
     * booked earlier the same day, and Auth-A's 200.00 hold went back when it settled on
     * Day 4, so nothing else is reserved. A 90.00 hold takes it to -245.00, and the
     * non-negotiable rule requires the result to remain at or above zero.
     */
    public function testAuthBIsDeclinedOnDayFive(): void
    {
        $this->replayThroughE7();

        $verdict = $this->assess('Auth-B', '90.00', 5);

        self::assertTrue($verdict->isDeclined());
        self::assertSame('-155.00', $verdict->availableBefore->format());
        self::assertSame('-245.00', $verdict->availableAfter->format());
        self::assertSame('DECLINED', $verdict->state());
    }

    public function testADeclinedAuthorizationReservesNothing(): void
    {
        $this->replayThroughE7();

        $this->assess('Auth-B', '90.00', 5);

        self::assertFalse($this->holds->has(AuthorizationId::of('Auth-B')));
        self::assertSame('0.00', $this->holds->totalHeldFor($this->ledger->account(self::id()))->format());
        self::assertSame(5, $this->ledger->count(), 'and writes nothing to the ledger either');
    }

    /**
     * The decline does not hang on how Auth-A's hold was handled.
     *
     * Had the settlement left the 200.00 reserved instead of returning it, available would be
     * -355.00 and Auth-B would still fail. The decision is not close to its boundary, which
     * matters: it means no resolved ambiguity elsewhere in the build can quietly flip it.
     */
    public function testAuthBDeclinesEvenIfAuthAsHoldHadNeverBeenReleased(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();
        $this->replayDayThree();
        $this->ledger->append(
            LedgerEntry::settlement(self::id(), self::aed('185.00'), self::day(4), self::day(4), 'E5'),
        );
        // deliberately no release
        $this->replayBackdatedDebit();

        $verdict = $this->assess('Auth-B', '90.00', 5);

        self::assertTrue($verdict->isDeclined());
        self::assertSame('-355.00', $verdict->availableBefore->format());
    }

    public function testTheDeclineExplainsItselfWithTheArithmetic(): void
    {
        $this->replayThroughE7();

        self::assertSame(
            'DECLINED: available -155.00 less a hold of 90.00 leaves -245.00, below zero.',
            $this->assess('Auth-B', '90.00', 5)->reason(),
        );
    }

    public function testTheApprovalExplainsItselfToo(): void
    {
        $this->replayDayOne();

        self::assertSame(
            'APPROVED: available 250.00 less a hold of 200.00 leaves 50.00, at or above zero.',
            $this->assess('Auth-A', '200.00', 2)->reason(),
        );
    }

    // ================================================ the boundary

    /**
     * "remains at or above zero" is inclusive. Landing exactly on zero is approved; one minor
     * unit past it is not. The pair is here because the two are a single character apart in
     * the implementation.
     *
     * @return iterable<string, array{string, bool, string}>
     */
    public static function amountsAgainstABalanceOfTwoFifty(): iterable
    {
        yield 'well inside'      => ['200.00', true, '50.00'];
        yield 'exactly on zero'  => ['250.00', true, '0.00'];
        yield 'one minor unit over' => ['250.01', false, '-0.01'];
        yield 'far over'         => ['500.00', false, '-250.00'];
    }

    #[DataProvider('amountsAgainstABalanceOfTwoFifty')]
    public function testTheZeroBoundaryIsInclusive(string $amount, bool $approved, string $after): void
    {
        $this->replayDayOne();

        $verdict = $this->assess('Auth-X', $amount, 2);

        self::assertSame($approved, $verdict->approved);
        self::assertSame($after, $verdict->availableAfter->format());
    }

    /** A second authorization is decided against what the first already reserved. */
    public function testAnEarlierHoldIsSubtractedFromTheNext(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();

        $second = $this->assess('Auth-C', '60.00', 2);
        self::assertFalse($second->approved, '250.00 - 200.00 held - 60.00 is below zero');
        self::assertSame('50.00', $second->availableBefore->format());

        $third = $this->assess('Auth-D', '50.00', 2);
        self::assertTrue($third->approved, 'but exactly the remaining 50.00 fits');
    }

    // ================================================ the event-shaped entry point

    private function applyEvent(string $id, string $auth, string $amount, int $day): Decision
    {
        return $this->rule->apply(new AuthorizationEvent(
            EventId::of($id),
            self::id(),
            AuthorizationId::of($auth),
            self::aed($amount),
            self::day($day),
            self::day($day),
        ));
    }

    public function testE3IsLoggedAsApprovedWithItsArithmetic(): void
    {
        $this->replayDayOne();

        $decision = $this->applyEvent('E3', 'Auth-A', '200.00', 2);

        self::assertSame(EventOutcome::APPROVED, $decision->outcome);
        self::assertSame('E3', $decision->event->value);
        self::assertSame(2, $decision->day->number);
        self::assertSame(
            'APPROVED: available 250.00 less a hold of 200.00 leaves 50.00, at or above zero.',
            $decision->reason,
        );
    }

    /**
     * E8 posts nothing and reserves nothing, so this record is its only trace. An unlogged
     * decline is indistinguishable from an event the engine never saw — which is precisely
     * the failure the DecisionLog exists to rule out.
     */
    public function testE8IsLoggedAsDeclinedWithTheBalanceItWasRefusedAgainst(): void
    {
        $this->replayThroughE7();

        $decision = $this->applyEvent('E8', 'Auth-B', '90.00', 5);

        self::assertSame(EventOutcome::DECLINED, $decision->outcome);
        self::assertSame(
            'DECLINED: available -155.00 less a hold of 90.00 leaves -245.00, below zero.',
            $decision->reason,
        );
        self::assertFalse($this->holds->has(AuthorizationId::of('Auth-B')));
    }

    /**
     * A decline is recorded but is not an error. The available-balance rule working exactly as
     * the brief specifies is the ordinary path for Auth-B; filing it under errors would report
     * correct behaviour as a fault.
     */
    public function testADeclineIsOnTheRecordWithoutBeingAnError(): void
    {
        $this->replayThroughE7();
        $log = new DecisionLog();

        $log->record($this->applyEvent('E8', 'Auth-B', '90.00', 5));

        self::assertCount(1, $log->all());
        self::assertCount(0, $log->rejections());
        self::assertFalse($log->all()[0]->outcome->isError());
    }

    public function testAnAuthorizationLeavesATraceWhicheverWayItGoes(): void
    {
        $this->replayDayOne();
        $log = new DecisionLog();
        $entriesBefore = $this->ledger->count();

        $log->record($this->applyEvent('E3', 'Auth-A', '200.00', 2));
        $log->record($this->applyEvent('E8', 'Auth-B', '500.00', 2));

        self::assertSame(
            ['APPROVED', 'DECLINED'],
            array_map(static fn (Decision $d): string => $d->outcome->value, $log->all()),
        );
        self::assertSame($entriesBefore, $this->ledger->count(), 'and neither wrote to the ledger');
    }

    // ================================================ guards

    public function testRefusesAnAuthorizationInTheAccountsWrongCurrency(): void
    {
        $this->expectException(AccountCurrencyMismatch::class);

        $this->rule->assess(
            AuthorizationId::of('Auth-A'),
            self::id(),
            Money::of('90.000', Currency::BHD),
            self::day(5),
        );
    }

    /**
     * A negative hold would raise available balance rather than reduce it — an authorization
     * that manufactures headroom. Refused before the balance test, not after it.
     *
     * @return iterable<string, array{string}>
     */
    public static function amountsThatReserveNothing(): iterable
    {
        yield 'zero' => ['0.00'];
        yield 'negative' => ['-90.00'];
    }

    #[DataProvider('amountsThatReserveNothing')]
    public function testRefusesAnAuthorizationThatReservesNothing(string $amount): void
    {
        $this->replayDayOne();

        $this->expectException(InvalidHold::class);
        $this->assess('Auth-A', $amount, 2);
    }

    /**
     * The same guard where it is actually load-bearing.
     *
     * On a healthy account a negative amount is caught either way, because Hold::place would
     * refuse it a few lines later. On an overdrawn one it would not be: available is -155.00,
     * so a -90.00 "hold" leaves -65.00, still negative, and the rule would report a decline —
     * a plausible answer to a request that was never coherent. It throws instead.
     */
    #[DataProvider('amountsThatReserveNothing')]
    public function testRefusesAnIncoherentAmountRatherThanDecliningIt(string $amount): void
    {
        $this->replayThroughE7();

        $this->expectException(InvalidHold::class);
        $this->assess('Auth-B', $amount, 5);
    }

    public function testRefusesASecondAuthorizationClaimingALiveId(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();

        $this->expectException(DuplicateAuthorization::class);
        $this->assess('Auth-A', '10.00', 2);
    }

    /**
     * A declined authorization leaves its id free. Nothing was reserved under it, so a later
     * event may legitimately reuse it — the duplicate guard protects live reservations, not
     * names.
     */
    public function testADeclinedAuthorizationDoesNotBurnItsId(): void
    {
        $this->replayThroughE7();
        self::assertSame(EventOutcome::DECLINED, $this->applyEvent('E8', 'Auth-B', '90.00', 5)->outcome);

        $this->ledger->append(LedgerEntry::credit(self::id(), self::aed('1000.00'), self::day(5), self::day(5)));

        self::assertSame(EventOutcome::APPROVED, $this->applyEvent('E8b', 'Auth-B', '90.00', 5)->outcome);
        self::assertTrue($this->holds->has(AuthorizationId::of('Auth-B')));
    }
}
