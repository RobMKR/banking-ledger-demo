<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\Exception\DuplicateAuthorization;
use Ledger\Domain\Ledger\Exception\HoldAlreadyReleased;
use Ledger\Domain\Ledger\Exception\UnknownAuthorization;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoldRegistry::class)]
final class HoldRegistryTest extends TestCase
{
    private const ACC1 = 'ACC-001';
    private const ACC2 = 'ACC-002';

    private static function auth(string $id): AuthorizationId
    {
        return AuthorizationId::of($id);
    }

    private static function hold(string $id, string $amount, string $account = self::ACC1, int $day = 2): Hold
    {
        $currency = $account === self::ACC2 ? Currency::BHD : Currency::AED;

        return Hold::place(
            self::auth($id),
            AccountId::of($account),
            Money::of($amount, $currency),
            LedgerDay::of($day),
        );
    }

    private static function accountOne(): Account
    {
        return Account::emptyIn(self::ACC1, Currency::AED);
    }

    // ==================================================== placing

    public function testAPlacedHoldCanBeFoundByItsAuthorizationId(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));

        self::assertTrue($registry->has(self::auth('Auth-A')));
        self::assertSame('200.00', $registry->find(self::auth('Auth-A'))->amount->format());
        self::assertSame(1, $registry->count());
    }

    public function testAnUnplacedAuthorizationIsUnknown(): void
    {
        $registry = new HoldRegistry();

        self::assertFalse($registry->has(self::auth('Auth-Z')));

        // The shape of E6's problem: a settlement arrives for an authorization nobody issued.
        $this->expectException(UnknownAuthorization::class);
        $registry->find(self::auth('Auth-Z'));
    }

    public function testRefusesTwoHoldsUnderOneAuthorizationId(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));

        $this->expectException(DuplicateAuthorization::class);
        $registry->place(self::hold('Auth-A', '10.00'));
    }

    // ==================================================== releasing

    public function testReleasingMarksTheHoldInactiveInTheRegistryToo(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));

        $released = $registry->release(self::auth('Auth-A'), LedgerDay::of(4));

        self::assertFalse($released->isActive());
        self::assertFalse($registry->find(self::auth('Auth-A'))->isActive());
        self::assertSame([], $registry->activeFor(AccountId::of(self::ACC1)));
    }

    public function testRefusesToReleaseAnAuthorizationItNeverHeld(): void
    {
        $this->expectException(UnknownAuthorization::class);

        (new HoldRegistry())->release(self::auth('Auth-Z'), LedgerDay::of(4));
    }

    public function testRefusesToReleaseTheSameHoldTwice(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));
        $registry->release(self::auth('Auth-A'), LedgerDay::of(4));

        $this->expectException(HoldAlreadyReleased::class);
        $registry->release(self::auth('Auth-A'), LedgerDay::of(5));
    }

    /**
     * A released hold is not forgotten. The registry is the record of what was reserved and
     * when it came back, which is what lets the Day 4 report explain why available balance
     * rose by 200.00 without a single ledger entry doing it.
     */
    public function testAReleasedHoldStaysInTheRecord(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));
        $registry->release(self::auth('Auth-A'), LedgerDay::of(4));

        self::assertCount(1, $registry->all());
        self::assertSame(2, $registry->all()[0]->placedOn->number);
        self::assertSame(4, $registry->all()[0]->releasedOn?->number);
    }

    // ==================================================== totals

    public function testTotalIsZeroInTheAccountsOwnCurrencyWhenNothingIsHeld(): void
    {
        self::assertSame('0.00', (new HoldRegistry())->totalHeldFor(self::accountOne())->format());
        self::assertSame(
            '0.000',
            (new HoldRegistry())->totalHeldFor(Account::emptyIn(self::ACC2, Currency::BHD))->format(),
        );
    }

    public function testTotalSumsEveryActiveHold(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));
        $registry->place(self::hold('Auth-B', '90.00', day: 5));

        self::assertSame('290.00', $registry->totalHeldFor(self::accountOne())->format());
        self::assertCount(2, $registry->activeFor(AccountId::of(self::ACC1)));
    }

    public function testAReleasedHoldStopsCountingTowardTheTotal(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));
        $registry->place(self::hold('Auth-B', '90.00', day: 5));
        $registry->release(self::auth('Auth-A'), LedgerDay::of(4));

        self::assertSame('90.00', $registry->totalHeldFor(self::accountOne())->format());
    }

    /** Holds are per-account. ACC-002 is never asked to fund a hold placed on ACC-001. */
    public function testHoldsOnOneAccountDoNotCountAgainstAnother(): void
    {
        $registry = new HoldRegistry();
        $registry->place(self::hold('Auth-A', '200.00'));
        $registry->place(self::hold('Auth-C', '1.000', self::ACC2, 5));

        self::assertSame('200.00', $registry->totalHeldFor(self::accountOne())->format());
        self::assertSame(
            '1.000',
            $registry->totalHeldFor(Account::emptyIn(self::ACC2, Currency::BHD))->format(),
        );
    }
}
