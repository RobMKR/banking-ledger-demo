<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Money;

use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Exception\InvalidRate;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Money\Rate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rate::class)]
final class RateTest extends TestCase
{
    /** The brief's 0.04% per day is exactly 4 basis points — no float required. */
    private const DAILY_INTEREST = 4;

    public function testHoldsTheRateAsAnExactRational(): void
    {
        $rate = Rate::fromBasisPoints(self::DAILY_INTEREST);

        self::assertSame(4, $rate->numerator());
        self::assertSame(10_000, $rate->denominator());
        self::assertSame('0.04%', (string) $rate);
    }

    public function testRendersWholePercentages(): void
    {
        self::assertSame('1.00%', (string) Rate::fromBasisPoints(100));
        self::assertSame('14.60%', (string) Rate::fromBasisPoints(1460));
        self::assertSame('0.00%', (string) Rate::fromBasisPoints(0));
    }

    public function testRefusesNegativeRates(): void
    {
        $this->expectException(InvalidRate::class);

        Rate::fromBasisPoints(-4);
    }

    // ------------------------------------------------------- applying the rate

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function aedDailyAccruals(): iterable
    {
        yield 'D1 250.00' => ['250.00', '0.10'];
        yield 'D2 225.00' => ['225.00', '0.09'];
        yield 'D3 625.00' => ['625.00', '0.25'];
        yield 'D4 415.00' => ['415.00', '0.17'];   // 0.166 rounds up
        yield 'D5 390.00' => ['390.00', '0.16'];   // 0.156 rounds down
    }

    /** The six ACC-001 accruals that capitalize to 0.93 at the end of Day 6. */
    #[DataProvider('aedDailyAccruals')]
    public function testProducesTheAssessmentsDailyAccruals(string $balance, string $expected): void
    {
        $accrual = Money::of($balance, Currency::AED)
            ->multipliedBy(Rate::fromBasisPoints(self::DAILY_INTEREST));

        self::assertSame($expected, $accrual->format());
    }

    public function testTheSixDailyAccrualsSumToTheCapitalizedTotal(): void
    {
        $rate = Rate::fromBasisPoints(self::DAILY_INTEREST);
        $balances = ['250.00', '225.00', '625.00', '415.00', '390.00', '390.00'];

        $total = Money::zero(Currency::AED);
        foreach ($balances as $balance) {
            $total = $total->plus(Money::of($balance, Currency::AED)->multipliedBy($rate));
        }

        self::assertSame('0.93', $total->format());
    }

    public function testAccruesAtBhdPrecision(): void
    {
        $accrual = Money::of('10.000', Currency::BHD)
            ->multipliedBy(Rate::fromBasisPoints(self::DAILY_INTEREST));

        self::assertSame('0.004', $accrual->format());
    }

    /**
     * NUMBERS.md's "why not half it" answer for the rate: 0.04%/day sets a dust threshold at
     * AED 12.50, below which a day accrues nothing at all. Halving the rate would double that
     * threshold and silently zero more days.
     */
    public function testAedDustThresholdSitsAtTwelveFifty(): void
    {
        $rate = Rate::fromBasisPoints(self::DAILY_INTEREST);

        self::assertSame('0.00', Money::of('12.49', Currency::AED)->multipliedBy($rate)->format());
        self::assertSame('0.01', Money::of('12.50', Currency::AED)->multipliedBy($rate)->format());
    }

    public function testBhdDustThresholdSitsAtOnePointTwoFive(): void
    {
        $rate = Rate::fromBasisPoints(self::DAILY_INTEREST);

        self::assertSame('0.000', Money::of('1.249', Currency::BHD)->multipliedBy($rate)->format());
        self::assertSame('0.001', Money::of('1.250', Currency::BHD)->multipliedBy($rate)->format());
    }

    public function testAZeroRateAccruesNothing(): void
    {
        self::assertTrue(
            Money::of('1200.00', Currency::AED)
                ->multipliedBy(Rate::fromBasisPoints(0))
                ->isZero(),
        );
    }

    /** Interest is credited on positive balances only, but the arithmetic itself is total. */
    public function testAppliesToNegativeBalancesWithoutSpecialCasing(): void
    {
        $accrual = Money::of('-370.00', Currency::AED)
            ->multipliedBy(Rate::fromBasisPoints(self::DAILY_INTEREST));

        self::assertSame('-0.15', $accrual->format());   // -0.148 away from zero
    }
}
