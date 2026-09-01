<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\Exception\AccountCurrencyMismatch;
use Ledger\Domain\Ledger\Exception\BackdatedBooking;
use Ledger\Domain\Ledger\Exception\UnknownAccount;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ledger::class)]
final class LedgerTest extends TestCase
{
    private const ACC1 = 'ACC-001';
    private const ACC2 = 'ACC-002';

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }

    private static function id(string $value = self::ACC1): AccountId
    {
        return AccountId::of($value);
    }

    private static function emptyLedger(): Ledger
    {
        return new Ledger(
            Account::emptyIn(self::ACC1, Currency::AED),
            Account::emptyIn(self::ACC2, Currency::BHD),
        );
    }

    /**
     * The assessment's financial events for ACC-001, appended in booked order.
     *
     * Only the six that move money and exist at this step: no fees (step 9), no interest
     * (step 10). Authorizations move no money, so they are not entries at all.
     *
     *   E1  D1  CREDIT     1200.00  value_date D1
     *   E2  D1  DEBIT       950.00  value_date D1
     *   E4  D3  CREDIT      400.00  value_date D3
     *   E5  D4  SETTLEMENT  185.00  value_date D4
     *   E7  D5  DEBIT       620.00  value_date D2   <- backdated
     *   E9  D6  REVERSAL of E7      value_date D2   <- backdated
     */
    private static function assessmentLedger(): Ledger
    {
        $ledger = self::emptyLedger();
        $acc = self::id();

        $ledger->append(LedgerEntry::credit($acc, self::aed('1200.00'), self::day(1), self::day(1), 'E1'));
        $ledger->append(LedgerEntry::debit($acc, self::aed('950.00'), self::day(1), self::day(1), 'E2'));
        $ledger->append(LedgerEntry::credit($acc, self::aed('400.00'), self::day(3), self::day(3), 'E4'));
        $ledger->append(LedgerEntry::settlement($acc, self::aed('185.00'), self::day(4), self::day(4), 'E5'));

        $e7 = LedgerEntry::debit($acc, self::aed('620.00'), self::day(2), self::day(5), 'E7');
        $ledger->append($e7);
        $ledger->append(LedgerEntry::reversalOf($e7, self::day(6), 'E9'));

        return $ledger;
    }

    private function assertBalance(string $expected, Ledger $ledger, int $valueDate, int $knownAsOf): void
    {
        self::assertSame(
            $expected,
            $ledger->balanceAsOf(self::id(), self::day($valueDate), self::day($knownAsOf))->format(),
            sprintf('balance for Day %d as known at close of Day %d', $valueDate, $knownAsOf),
        );
    }

    // ==================================================== the bitemporal matrix

    /**
     * Before E7 arrives, the ledger reads exactly as you would expect from the events booked
     * so far. Nothing is overdrawn on any day.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function balancesKnownAtCloseOfDayFour(): iterable
    {
        yield 'D1' => [1, '250.00'];
        yield 'D2' => [2, '250.00'];
        yield 'D3' => [3, '650.00'];
        yield 'D4' => [4, '465.00'];
    }

    #[DataProvider('balancesKnownAtCloseOfDayFour')]
    public function testBalancesAsKnownAtCloseOfDayFour(int $valueDate, string $expected): void
    {
        $this->assertBalance($expected, self::assessmentLedger(), $valueDate, 4);
    }

    /**
     * E7 arrives on Day 5 carrying value_date Day 2. Every day from D2 onward is restated,
     * retroactively, without a single stored record changing.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function balancesKnownAtCloseOfDayFive(): iterable
    {
        yield 'D1 untouched'      => [1, '250.00'];
        yield 'D2 goes overdrawn' => [2, '-370.00'];
        yield 'D3'                => [3, '30.00'];
        yield 'D4'                => [4, '-155.00'];
        yield 'D5'                => [5, '-155.00'];
    }

    #[DataProvider('balancesKnownAtCloseOfDayFive')]
    public function testBalancesAsKnownAtCloseOfDayFive(int $valueDate, string $expected): void
    {
        $this->assertBalance($expected, self::assessmentLedger(), $valueDate, 5);
    }

    /**
     * E9 reverses E7 on Day 6. The pair nets to zero at value_date D2, so the pre-E7 figures
     * return — but only because no fee has been assessed yet. Once step 9 adds them, D2 will
     * close at 225.00 rather than 250.00, which is what refutes criterion 6.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function balancesKnownAtCloseOfDaySix(): iterable
    {
        yield 'D1' => [1, '250.00'];
        yield 'D2' => [2, '250.00'];
        yield 'D3' => [3, '650.00'];
        yield 'D4' => [4, '465.00'];
        yield 'D5' => [5, '465.00'];
        yield 'D6' => [6, '465.00'];
    }

    #[DataProvider('balancesKnownAtCloseOfDaySix')]
    public function testBalancesAsKnownAtCloseOfDaySix(int $valueDate, string $expected): void
    {
        $this->assertBalance($expected, self::assessmentLedger(), $valueDate, 6);
    }

    // ==================================================== the criteria

    /**
     * ACCEPTANCE CRITERION 1, accepted.
     *
     * "The Day 2 closing ledger balance, evaluated at end of Day 5 and before any fee is
     * assessed, is AED -370.00."
     *
     * Correct, and precisely worded: it fixes both coordinates. 1200.00 - 950.00 - 620.00.
     * At the close of Day 5 the ledger has never heard of E9, which is booked on Day 6.
     */
    public function testCriterionOneDayTwoAtCloseOfDayFiveIsMinusThreeSeventy(): void
    {
        $this->assertBalance('-370.00', self::assessmentLedger(), valueDate: 2, knownAsOf: 5);
    }

    /**
     * The heart of the exercise: one value-date bucket, two questions, two correct answers.
     *
     * Strip either coordinate from the question and it becomes unanswerable — which is why
     * balanceAsOf() requires both and offers no convenience that defaults one away.
     */
    public function testDayTwoHasADifferentBalanceDependingOnWhenItIsAsked(): void
    {
        $ledger = self::assessmentLedger();

        $this->assertBalance('250.00', $ledger, valueDate: 2, knownAsOf: 4);   // before E7
        $this->assertBalance('-370.00', $ledger, valueDate: 2, knownAsOf: 5);  // after E7
        $this->assertBalance('250.00', $ledger, valueDate: 2, knownAsOf: 6);   // after E9
    }

    // ==================================================== the two filters

    public function testEntriesWithALaterValueDateAreExcluded(): void
    {
        $ledger = self::emptyLedger();
        $ledger->append(LedgerEntry::credit(self::id(), self::aed('400.00'), self::day(3), self::day(3), 'E4'));

        $this->assertBalance('0.00', $ledger, valueDate: 2, knownAsOf: 6);
        $this->assertBalance('400.00', $ledger, valueDate: 3, knownAsOf: 6);
    }

    public function testEntriesBookedAfterTheKnowledgeDateAreExcluded(): void
    {
        $ledger = self::emptyLedger();
        $ledger->append(LedgerEntry::debit(self::id(), self::aed('620.00'), self::day(2), self::day(5), 'E7'));

        $this->assertBalance('0.00', $ledger, valueDate: 6, knownAsOf: 4);
        $this->assertBalance('-620.00', $ledger, valueDate: 6, knownAsOf: 5);
    }

    /** Both filters are independent; neither subsumes the other. */
    public function testBothFiltersApplyTogether(): void
    {
        $ledger = self::emptyLedger();
        $ledger->append(LedgerEntry::debit(self::id(), self::aed('620.00'), self::day(2), self::day(5), 'E7'));

        $this->assertBalance('0.00', $ledger, valueDate: 1, knownAsOf: 5);  // value date too late
        $this->assertBalance('0.00', $ledger, valueDate: 2, knownAsOf: 4);  // not yet booked
        $this->assertBalance('-620.00', $ledger, valueDate: 2, knownAsOf: 5);
    }

    // ==================================================== accounts

    public function testOpeningBalanceIsIncluded(): void
    {
        $ledger = new Ledger(Account::opening('ACC-009', self::aed('100.00')));

        self::assertSame(
            '100.00',
            $ledger->balanceAsOf(AccountId::of('ACC-009'), self::day(1), self::day(1))->format(),
        );
    }

    public function testAccountsDoNotLeakIntoEachOther(): void
    {
        $ledger = self::emptyLedger();
        $ledger->append(LedgerEntry::credit(self::id(self::ACC1), self::aed('1200.00'), self::day(1), self::day(1)));
        $ledger->append(LedgerEntry::credit(
            self::id(self::ACC2), Money::of('10.000', Currency::BHD), self::day(5), self::day(5),
        ));

        $this->assertBalance('1200.00', $ledger, valueDate: 6, knownAsOf: 6);
        self::assertSame(
            '10.000',
            $ledger->balanceAsOf(self::id(self::ACC2), self::day(6), self::day(6))->format(),
        );
    }

    public function testEachAccountKeepsItsOwnPrecision(): void
    {
        $ledger = self::emptyLedger();

        self::assertSame('0.00', $ledger->balanceAsOf(self::id(self::ACC1), self::day(1), self::day(1))->format());
        self::assertSame('0.000', $ledger->balanceAsOf(self::id(self::ACC2), self::day(1), self::day(1))->format());
    }

    // ==================================================== append guards

    public function testRefusesAnEntryForAnUnknownAccount(): void
    {
        $this->expectException(UnknownAccount::class);

        self::emptyLedger()->append(
            LedgerEntry::credit(AccountId::of('ACC-999'), self::aed('1.00'), self::day(1), self::day(1)),
        );
    }

    public function testRefusesAnEntryInTheWrongCurrency(): void
    {
        $this->expectException(AccountCurrencyMismatch::class);

        self::emptyLedger()->append(LedgerEntry::credit(
            self::id(self::ACC1), Money::of('1.000', Currency::BHD), self::day(1), self::day(1),
        ));
    }

    /**
     * A backdated value date is legitimate and expected. A backdated *booking* is not: it
     * would rewrite what the ledger already knew on a day that has passed.
     */
    public function testAcceptsABackdatedValueDateButRefusesABackdatedBooking(): void
    {
        $ledger = self::emptyLedger();
        $ledger->append(LedgerEntry::debit(self::id(), self::aed('620.00'), self::day(2), self::day(5), 'E7'));

        // Same booking day is fine — several events share one day.
        $ledger->append(LedgerEntry::credit(self::id(), self::aed('1.00'), self::day(5), self::day(5)));

        $this->expectException(BackdatedBooking::class);
        $ledger->append(LedgerEntry::credit(self::id(), self::aed('1.00'), self::day(1), self::day(4)));
    }

    // ==================================================== append-only

    /**
     * Append-only is structural, not promised in a docblock: the class exposes no way to
     * remove or replace an entry, and every entry is readonly. If someone later adds one,
     * this test fails and the ledger's central guarantee gets re-examined.
     */
    public function testExposesNoWayToRemoveOrAlterAnEntry(): void
    {
        $mutators = array_filter(
            (new \ReflectionClass(Ledger::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => (bool) preg_match(
                '/^(remove|delete|replace|update|set|clear|reset|pop|shift|splice|sort)/i',
                $m->getName(),
            ),
        );

        self::assertSame([], array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $mutators));
        self::assertTrue((new \ReflectionClass(LedgerEntry::class))->isReadOnly());
    }

    public function testEntriesAreKeptInAppendOrder(): void
    {
        $ledger = self::assessmentLedger();

        self::assertSame(
            ['E1', 'E2', 'E4', 'E5', 'E7', 'E9'],
            array_map(static fn (LedgerEntry $e): ?string => $e->reference, $ledger->entries()),
        );
        self::assertSame(6, $ledger->count());
    }

    public function testReversalDoesNotRemoveTheOriginal(): void
    {
        $ledger = self::assessmentLedger();
        $references = array_map(static fn (LedgerEntry $e): ?string => $e->reference, $ledger->entries());

        self::assertContains('E7', $references, 'the reversed entry is still in the ledger');
        self::assertContains('E9', $references, 'and so is the reversal');
    }

    public function testEntriesForAnAccountCanBeLimitedToWhatWasKnown(): void
    {
        $ledger = self::assessmentLedger();

        self::assertCount(4, $ledger->entriesFor(self::id(), self::day(4)));
        self::assertCount(5, $ledger->entriesFor(self::id(), self::day(5)));
        self::assertCount(6, $ledger->entriesFor(self::id()));
    }
}
