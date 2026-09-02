<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Money;

use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Exception\ArithmeticOverflow;
use Ledger\Domain\Money\Exception\CurrencyMismatch;
use Ledger\Domain\Money\Exception\InvalidAmount;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    // ---------------------------------------------------------------- construction

    /** @return iterable<string, array{string, Currency, int}> */
    public static function decimalStrings(): iterable
    {
        yield 'AED whole'            => ['1200.00', Currency::AED, 120000];
        yield 'AED negative'         => ['-950.00', Currency::AED, -950_00];
        yield 'AED zero'             => ['0.00', Currency::AED, 0];
        yield 'AED negative zero'    => ['-0.00', Currency::AED, 0];
        yield 'AED no fraction'      => ['250', Currency::AED, 25000];
        yield 'AED short fraction'   => ['250.5', Currency::AED, 25050];
        yield 'AED sub-unit'         => ['0.04', Currency::AED, 4];
        yield 'AED leading zeros'    => ['0000250.00', Currency::AED, 25000];
        yield 'BHD three decimals'   => ['10.000', Currency::BHD, 10000];
        yield 'BHD instalment'       => ['3.334', Currency::BHD, 3334];
        yield 'BHD accrual'          => ['0.004', Currency::BHD, 4];
    }

    #[DataProvider('decimalStrings')]
    public function testParsesDecimalStringsIntoMinorUnits(
        string $input,
        Currency $currency,
        int $expectedMinor,
    ): void {
        self::assertSame($expectedMinor, Money::of($input, $currency)->minor);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAmounts(): iterable
    {
        yield 'empty'            => [''];
        yield 'letters'          => ['abc'];
        yield 'thousands comma'  => ['1,200.00'];
        yield 'trailing dot'     => ['12.'];
        yield 'leading dot'      => ['.5'];
        yield 'double sign'      => ['--5.00'];
        yield 'trailing sign'    => ['5.00-'];
        yield 'whitespace'       => [' 5.00'];
        yield 'exponent form'    => ['1e3'];
        yield 'plus sign'        => ['+5.00'];
    }

    #[DataProvider('malformedAmounts')]
    public function testRejectsMalformedAmounts(string $input): void
    {
        $this->expectException(InvalidAmount::class);

        Money::of($input, Currency::AED);
    }

    /**
     * Input is never silently rounded. The brief allows rounding only at explicit, named
     * boundaries — accepting "3.3333" as BHD would make the constructor one of them.
     */
    public function testRefusesMorePrecisionThanTheCurrencyHolds(): void
    {
        $this->expectException(InvalidAmount::class);
        $this->expectExceptionMessageMatches('/more than 3 decimal places/');

        Money::of('3.3333', Currency::BHD);
    }

    public function testAedRefusesAThirdDecimalPlaceThatBhdWouldAccept(): void
    {
        self::assertSame(3334, Money::of('3.334', Currency::BHD)->minor);

        $this->expectException(InvalidAmount::class);

        Money::of('3.334', Currency::AED);
    }

    public function testRejectsAmountsBeyondTheIntegerRange(): void
    {
        $this->expectException(ArithmeticOverflow::class);

        Money::of('99999999999999999999.00', Currency::AED);
    }

    public function testRefusingAnOverRangeAmountEmitsNoDiagnostic(): void
    {
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $threw = false;
            try {
                Money::of('99999999999999999999.00', Currency::AED);
            } catch (ArithmeticOverflow) {
                $threw = true;
            }
        } finally {
            restore_error_handler();
        }

        self::assertTrue($threw, 'the amount must still be refused');
        self::assertSame([], $raised, 'and refused without raising a diagnostic');
    }

    public function testTheIntegerBoundaryIsExactNotApproximate(): void
    {
        // PHP_INT_MAX minor units, as a 2dp amount: 92233720368547758.07
        $max = Money::of('92233720368547758.07', Currency::AED);
        self::assertSame(PHP_INT_MAX, $max->minor);

        // One minor unit more. Same digit count, still overflows.
        $this->expectException(ArithmeticOverflow::class);
        Money::of('92233720368547758.08', Currency::AED);
    }

    public function testZeroIsZeroInItsCurrency(): void
    {
        $zero = Money::zero(Currency::BHD);

        self::assertTrue($zero->isZero());
        self::assertSame('0.000', $zero->format());
        self::assertSame(Currency::BHD, $zero->currency);
    }

    // ---------------------------------------------------------------- formatting

    /** @return iterable<string, array{int, Currency, string}> */
    public static function formatCases(): iterable
    {
        yield 'AED whole'        => [120000, Currency::AED, '1200.00'];
        yield 'AED negative'     => [-37000, Currency::AED, '-370.00'];
        yield 'AED sub-unit'     => [4, Currency::AED, '0.04'];
        yield 'AED zero'         => [0, Currency::AED, '0.00'];
        yield 'AED one minor'    => [1, Currency::AED, '0.01'];
        yield 'BHD three places' => [10000, Currency::BHD, '10.000'];
        yield 'BHD one minor'    => [1, Currency::BHD, '0.001'];
        yield 'BHD negative'     => [-3334, Currency::BHD, '-3.334'];
    }

    #[DataProvider('formatCases')]
    public function testFormatsAtItsOwnPrecision(
        int $minor,
        Currency $currency,
        string $expected,
    ): void {
        self::assertSame($expected, Money::ofMinor($minor, $currency)->format());
    }

    #[DataProvider('decimalStrings')]
    public function testFormattingRoundTripsThroughParsing(
        string $input,
        Currency $currency,
        int $minor,
    ): void {
        $money = Money::ofMinor($minor, $currency);

        self::assertTrue($money->equals(Money::of($money->format(), $currency)));
    }

    public function testStringificationCarriesTheCurrency(): void
    {
        self::assertSame('1200.00 AED', (string) Money::of('1200.00', Currency::AED));
    }

    // ---------------------------------------------------------------- arithmetic

    /** E1 then E2: the opening two events of the assessment stream. */
    public function testAddsAndSubtracts(): void
    {
        $balance = Money::zero(Currency::AED)
            ->plus(Money::of('1200.00', Currency::AED))
            ->minus(Money::of('950.00', Currency::AED));

        self::assertSame('250.00', $balance->format());
    }

    public function testSubtractionCrossesZeroIntoOverdraft(): void
    {
        $balance = Money::of('250.00', Currency::AED)
            ->minus(Money::of('620.00', Currency::AED));

        self::assertSame('-370.00', $balance->format());
        self::assertTrue($balance->isNegative());
    }

    /** A reversal is an appended inverse entry, so negation has to be exact. */
    public function testNegationIsExactAndSelfInverse(): void
    {
        $debit = Money::of('-620.00', Currency::AED);

        self::assertSame('620.00', $debit->negated()->format());
        self::assertTrue($debit->equals($debit->negated()->negated()));
        self::assertTrue($debit->plus($debit->negated())->isZero());
    }

    public function testAdditionOverflowIsRaisedNotSilentlyFloated(): void
    {
        $this->expectException(ArithmeticOverflow::class);

        Money::ofMinor(PHP_INT_MAX, Currency::AED)
            ->plus(Money::ofMinor(1, Currency::AED));
    }

    public function testSubtractionOverflowIsRaisedNotSilentlyFloated(): void
    {
        $this->expectException(ArithmeticOverflow::class);

        Money::ofMinor(-PHP_INT_MAX, Currency::AED)
            ->minus(Money::ofMinor(2, Currency::AED));
    }

    public function testPhpIntMinIsRefusedBecauseItCannotBeNegated(): void
    {
        $this->expectException(ArithmeticOverflow::class);

        Money::ofMinor(PHP_INT_MIN, Currency::AED);
    }

    // ---------------------------------------------------------------- currency safety

    public function testWillNotAddAcrossCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('1.00', Currency::AED)->plus(Money::of('1.000', Currency::BHD));
    }

    public function testWillNotCompareAcrossCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('1.00', Currency::AED)->compareTo(Money::of('1.000', Currency::BHD));
    }

    /** Equality is total, so it answers false across currencies rather than throwing. */
    public function testEqualityAcrossCurrenciesIsFalseRatherThanAnError(): void
    {
        self::assertFalse(
            Money::ofMinor(1000, Currency::AED)->equals(Money::ofMinor(1000, Currency::BHD)),
        );
    }

    // ---------------------------------------------------------------- comparison

    public function testComparesWithinACurrency(): void
    {
        $lower = Money::of('5.00', Currency::AED);
        $higher = Money::of('620.00', Currency::AED);

        self::assertSame(-1, $lower->compareTo($higher));
        self::assertSame(1, $higher->compareTo($lower));
        self::assertSame(0, $lower->compareTo(Money::of('5.00', Currency::AED)));
        self::assertTrue($lower->isLessThan($higher));
    }

    /**
     * The authorization rule is "available balance remains at or above zero", so the
     * boundary itself must be inclusive.
     */
    public function testZeroSatisfiesTheAtOrAboveZeroBoundary(): void
    {
        $zero = Money::zero(Currency::AED);

        self::assertTrue($zero->isGreaterThanOrEqualTo($zero));
        self::assertFalse($zero->isPositive());
        self::assertFalse($zero->isNegative());
    }

    public function testMoneyIsImmutable(): void
    {
        $original = Money::of('250.00', Currency::AED);
        $original->plus(Money::of('400.00', Currency::AED));

        self::assertSame('250.00', $original->format());
    }
}
