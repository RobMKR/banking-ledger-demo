<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\Exception\AccountCurrencyMismatch;
use Ledger\Domain\Ledger\Exception\InvalidAccountId;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Account::class)]
#[CoversClass(AccountId::class)]
final class AccountTest extends TestCase
{
    /** The two accounts of the brief, opening at zero in their own precision. */
    public function testOpensTheAssessmentsAccounts(): void
    {
        $aed = Account::emptyIn('ACC-001', Currency::AED);
        $bhd = Account::emptyIn('ACC-002', Currency::BHD);

        self::assertSame('ACC-001', $aed->id->value);
        self::assertSame('0.00', $aed->openingBalance->format());
        self::assertSame('0.000', $bhd->openingBalance->format());
    }

    public function testTakesItsCurrencyFromItsOpeningBalance(): void
    {
        $account = Account::opening('ACC-003', Money::of('100.000', Currency::BHD));

        self::assertSame(Currency::BHD, $account->currency);
        self::assertSame('100.000', $account->openingBalance->format());
    }

    public function testRejectsMoneyInAnotherCurrency(): void
    {
        $this->expectException(AccountCurrencyMismatch::class);

        Account::emptyIn('ACC-001', Currency::AED)
            ->assertHolds(Money::of('1.000', Currency::BHD));
    }

    public function testAcceptsMoneyInItsOwnCurrency(): void
    {
        $account = Account::emptyIn('ACC-001', Currency::AED);
        $account->assertHolds(Money::of('1200.00', Currency::AED));

        $this->expectNotToPerformAssertions();
    }

    public function testTrimsAndRefusesBlankIds(): void
    {
        self::assertTrue(AccountId::of('  ACC-001 ')->equals(AccountId::of('ACC-001')));

        $this->expectException(InvalidAccountId::class);
        AccountId::of('   ');
    }
}
