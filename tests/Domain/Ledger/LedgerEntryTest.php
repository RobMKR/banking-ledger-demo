<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Exception\MisdirectedEntry;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerEntry::class)]
#[CoversClass(EntryType::class)]
final class LedgerEntryTest extends TestCase
{
    private static function acc(): AccountId
    {
        return AccountId::of('ACC-001');
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }

    /** E1 — a credit keeps its sign. */
    public function testCreditIsStoredPositive(): void
    {
        $entry = LedgerEntry::credit(self::acc(), self::aed('1200.00'), LedgerDay::of(1), LedgerDay::of(1), 'E1');

        self::assertSame('1200.00', $entry->amount->format());
        self::assertSame(EntryType::CREDIT, $entry->type);
        self::assertSame('E1', $entry->reference);
    }

    /** E2 — a debit is given a positive figure and stores it negated. */
    public function testDebitIsStoredNegated(): void
    {
        $entry = LedgerEntry::debit(self::acc(), self::aed('950.00'), LedgerDay::of(1), LedgerDay::of(1), 'E2');

        self::assertSame('-950.00', $entry->amount->format());
        self::assertTrue($entry->amount->isNegative());
    }

    public function testSettlementAndFeeAreAlsoDebitDirection(): void
    {
        self::assertSame('-185.00', LedgerEntry::settlement(
            self::acc(), self::aed('185.00'), LedgerDay::of(4), LedgerDay::of(4), 'E5',
        )->amount->format());

        self::assertSame('-25.00', LedgerEntry::overdraftFee(
            self::acc(), self::aed('25.00'), LedgerDay::of(2), LedgerDay::of(5),
        )->amount->format());
    }

    public function testInterestIsCreditDirection(): void
    {
        $entry = LedgerEntry::interest(self::acc(), self::aed('0.93'), LedgerDay::of(6), LedgerDay::of(6));

        self::assertSame('0.93', $entry->amount->format());
        self::assertTrue($entry->type->isSystemGenerated());
    }

    /**
     * E9 reverses E7. It appends the inverse at the *original's* value date, keeping the pair
     * netted in the period the money belonged to, while booking on the day the reversal
     * arrived. Nothing is removed.
     */
    public function testReversalInvertsTheOriginalAndKeepsItsValueDate(): void
    {
        $e7 = LedgerEntry::debit(self::acc(), self::aed('620.00'), LedgerDay::of(2), LedgerDay::of(5), 'E7');
        $e9 = LedgerEntry::reversalOf($e7, LedgerDay::of(6), 'E9');

        self::assertSame('620.00', $e9->amount->format());
        self::assertSame(2, $e9->valueDate->number, 'reversal keeps the original value date');
        self::assertSame(6, $e9->bookedDay->number, 'but is booked on the day it arrived');
        self::assertTrue($e7->amount->plus($e9->amount)->isZero());
    }

    public function testRefusesAnEntryWhoseSignContradictsItsType(): void
    {
        $this->expectException(MisdirectedEntry::class);

        LedgerEntry::credit(self::acc(), self::aed('-100.00'), LedgerDay::of(1), LedgerDay::of(1));
    }

    public function testRecognisesABackdatedEntry(): void
    {
        $e7 = LedgerEntry::debit(self::acc(), self::aed('620.00'), LedgerDay::of(2), LedgerDay::of(5), 'E7');
        $e1 = LedgerEntry::credit(self::acc(), self::aed('1200.00'), LedgerDay::of(1), LedgerDay::of(1), 'E1');

        self::assertTrue($e7->isBackdated());
        self::assertFalse($e1->isBackdated());
    }

    public function testCountsTowardABalanceOnlyWhenBothDatesQualify(): void
    {
        // Booked D5, but the money belongs to D2.
        $e7 = LedgerEntry::debit(self::acc(), self::aed('620.00'), LedgerDay::of(2), LedgerDay::of(5), 'E7');

        self::assertTrue($e7->countsToward(LedgerDay::of(2), LedgerDay::of(5)));
        self::assertFalse($e7->countsToward(LedgerDay::of(2), LedgerDay::of(4)), 'not yet known on D4');
        self::assertFalse($e7->countsToward(LedgerDay::of(1), LedgerDay::of(6)), 'value date is after D1');
    }
}
