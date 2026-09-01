<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Money;

use Ledger\Domain\Money\Exception\ArithmeticOverflow;
use Ledger\Domain\Money\Exception\DivisionByZero;
use Ledger\Domain\Money\Rounding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Rounding::class)]
final class RoundingTest extends TestCase
{
    /** @return iterable<string, array{int, int, int}> */
    public static function divisions(): iterable
    {
        yield 'exact'                 => [40000, 10000, 4];
        yield 'below half rounds down' => [166000, 10000, 17];   // 16.6
        yield 'above half rounds up'   => [156000, 10000, 16];   // 15.6
        yield 'just under a half'      => [14999, 10000, 1];     // 1.4999
        yield 'just over a half'       => [15001, 10000, 2];     // 1.5001
        yield 'zero numerator'         => [0, 10000, 0];
        yield 'divides to zero'        => [4996, 10000, 0];      // 0.4996
    }

    #[DataProvider('divisions')]
    public function testDividesRoundingHalvesUp(int $numerator, int $denominator, int $expected): void
    {
        self::assertSame($expected, Rounding::divideHalfUp($numerator, $denominator));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function ties(): iterable
    {
        yield 'positive half'        => [5, 10, 1];       //  0.5 ->  1
        yield 'positive one and half' => [15, 10, 2];      //  1.5 ->  2
        yield 'positive two and half' => [25, 10, 3];      //  2.5 ->  3, not 2 (this is not half-even)
        yield 'negative half'         => [-5, 10, -1];     // -0.5 -> -1, away from zero
        yield 'negative one and half' => [-15, 10, -2];    // -1.5 -> -2
        yield 'negative divisor'      => [5, -10, -1];
        yield 'both negative'         => [-5, -10, 1];
    }

    /**
     * HALF_UP is read as "half away from zero", matching Java and PHP's round(). The
     * two-and-a-half case distinguishes it from HALF_EVEN, which would give 2.
     */
    #[DataProvider('ties')]
    public function testTiesRoundAwayFromZero(int $numerator, int $denominator, int $expected): void
    {
        self::assertSame($expected, Rounding::divideHalfUp($numerator, $denominator));
    }

    public function testNegativeDivisionRoundsTowardsTheLargerMagnitude(): void
    {
        self::assertSame(-2, Rounding::divideHalfUp(-166, 100));   // -1.66
        self::assertSame(-1, Rounding::divideHalfUp(-149, 100));   // -1.49
    }

    /**
     * NUMBERS.md claims the tie-breaking rule is not load-bearing for this dataset, because
     * no daily accrual lands on an exact half. A tie needs a balance of the form 25k + 12.50,
     * and none of the assessment's balances is one. This test is that claim, made executable
     * — if a future change introduces a balance that does tie, the choice of HALF_UP starts
     * mattering and the documentation must change with it.
     */
    public function testNoAccrualInTheAssessmentDatasetLandsOnATie(): void
    {
        // Every closing balance reachable in the replay, in AED minor units.
        $balances = [25000, 22500, 62500, 41500, 39000, 46500, 65000, 44000, 23500, 21000];

        foreach ($balances as $minor) {
            self::assertFalse(
                Rounding::landsOnATie($minor * 4, 10000),
                sprintf('Balance %d unexpectedly lands on a rounding tie', $minor),
            );
        }
    }

    public function testDetectsATieWhenThereIsOne(): void
    {
        // AED 12.50 is the smallest balance that accrues a non-zero amount, and it ties.
        self::assertTrue(Rounding::landsOnATie(1250 * 4, 10000));
        self::assertSame(1, Rounding::divideHalfUp(1250 * 4, 10000));
    }

    public function testRefusesDivisionByZero(): void
    {
        $this->expectException(DivisionByZero::class);

        Rounding::divideHalfUp(1, 0);
    }

    public function testGuardsAgainstOverflowWhileDoublingTheNumerator(): void
    {
        $this->expectException(ArithmeticOverflow::class);

        Rounding::divideHalfUp(PHP_INT_MAX, 3);
    }

    public function testRefusesIntegerMinBecauseItsMagnitudeIsNotRepresentable(): void
    {
        $this->expectException(ArithmeticOverflow::class);

        Rounding::divideHalfUp(PHP_INT_MIN, 10);
    }
}
