<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Rule;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\Exception\AccountCurrencyMismatch;
use Ledger\Domain\Ledger\Exception\DuplicateAuthorization;
use Ledger\Domain\Ledger\Exception\InvalidHold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\AuthorizationDecision;
use Ledger\Domain\Rule\AuthorizationRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationRule::class)]
#[CoversClass(AuthorizationDecision::class)]
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
        $this->rule = new AuthorizationRule($this->ledger, $this->holds);
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

    private function authorize(string $auth, string $amount, int $day): AuthorizationDecision
    {
        return $this->rule->authorize(AuthorizationId::of($auth), self::id(), self::aed($amount), self::day($day));
    }

    // ================================================ the assessment stream, event by event

    /** E1, E2 — Day 1. ACC-001 stands at 250.00. */
    private function replayDayOne(): void
    {
        $this->ledger->append(LedgerEntry::credit(self::id(), self::aed('1200.00'), self::day(1), self::day(1), 'E1'));
        $this->ledger->append(LedgerEntry::debit(self::id(), self::aed('950.00'), self::day(1), self::day(1), 'E2'));
    }

    /** E3 — Day 2. Auth-A asks to hold 200.00. */
    private function replayAuthA(): AuthorizationDecision
    {
        return $this->authorize('Auth-A', '200.00', 2);
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

        $decision = $this->replayAuthA();

        self::assertTrue($decision->approved);
        self::assertSame('250.00', $decision->availableBefore->format());
        self::assertSame('50.00', $decision->availableAfter->format());
        self::assertSame('APPROVED', $decision->state());
    }

    public function testAnApprovedAuthorizationReservesTheFunds(): void
    {
        $this->replayDayOne();
        $decision = $this->replayAuthA();

        self::assertTrue($this->holds->has(AuthorizationId::of('Auth-A')));
        self::assertTrue($this->holds->find(AuthorizationId::of('Auth-A'))->isActive());
        self::assertSame($decision->hold, $this->holds->find(AuthorizationId::of('Auth-A')));
        self::assertSame('200.00', $this->holds->totalHeldFor($this->ledger->account(self::id()))->format());
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

        $decision = $this->authorize('Auth-B', '90.00', 5);

        self::assertTrue($decision->isDeclined());
        self::assertSame('-155.00', $decision->availableBefore->format());
        self::assertSame('-245.00', $decision->availableAfter->format());
        self::assertSame('DECLINED', $decision->state());
    }

    public function testADeclinedAuthorizationReservesNothing(): void
    {
        $this->replayThroughE7();

        $this->authorize('Auth-B', '90.00', 5);

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

        $decision = $this->authorize('Auth-B', '90.00', 5);

        self::assertTrue($decision->isDeclined());
        self::assertSame('-355.00', $decision->availableBefore->format());
    }

    public function testTheDeclineExplainsItselfWithTheArithmetic(): void
    {
        $this->replayThroughE7();

        self::assertSame(
            'DECLINED: available -155.00 less a hold of 90.00 leaves -245.00, below zero.',
            $this->authorize('Auth-B', '90.00', 5)->reason(),
        );
    }

    public function testTheApprovalExplainsItselfToo(): void
    {
        $this->replayDayOne();

        self::assertSame(
            'APPROVED: available 250.00 less a hold of 200.00 leaves 50.00, at or above zero.',
            $this->replayAuthA()->reason(),
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

        $decision = $this->authorize('Auth-X', $amount, 2);

        self::assertSame($approved, $decision->approved);
        self::assertSame($after, $decision->availableAfter->format());
    }

    /** A second authorization is decided against what the first already reserved. */
    public function testAnEarlierHoldIsSubtractedFromTheNext(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();

        $second = $this->authorize('Auth-C', '60.00', 2);
        self::assertFalse($second->approved, '250.00 - 200.00 held - 60.00 is below zero');
        self::assertSame('50.00', $second->availableBefore->format());

        $third = $this->authorize('Auth-D', '50.00', 2);
        self::assertTrue($third->approved, 'but exactly the remaining 50.00 fits');
    }

    // ================================================ guards

    public function testRefusesAnAuthorizationInTheAccountsWrongCurrency(): void
    {
        $this->expectException(AccountCurrencyMismatch::class);

        $this->rule->authorize(
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
        $this->authorize('Auth-A', $amount, 2);
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
        $this->authorize('Auth-B', $amount, 5);
    }

    public function testRefusesASecondAuthorizationClaimingALiveId(): void
    {
        $this->replayDayOne();
        $this->replayAuthA();

        $this->expectException(DuplicateAuthorization::class);
        $this->authorize('Auth-A', '10.00', 2);
    }

    /**
     * A declined authorization leaves its id free. Nothing was reserved under it, so a later
     * event may legitimately reuse it — the duplicate guard protects live reservations, not
     * names.
     */
    public function testADeclinedAuthorizationDoesNotBurnItsId(): void
    {
        $this->replayThroughE7();
        $this->authorize('Auth-B', '90.00', 5);

        $this->ledger->append(LedgerEntry::credit(self::id(), self::aed('1000.00'), self::day(5), self::day(5)));

        self::assertTrue($this->authorize('Auth-B', '90.00', 5)->approved);
    }
}
