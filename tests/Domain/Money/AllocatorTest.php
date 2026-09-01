<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Money;

use Ledger\Domain\Money\Allocator;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Exception\InvalidAllocation;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Allocator::class)]
final class AllocatorTest extends TestCase
{
    /** @param list<Money> $allocation */
    private static function formatAll(array $allocation): array
    {
        return array_map(static fn (Money $part): string => $part->format(), $allocation);
    }

    /** @param list<Money> $allocation */
    private static function sum(array $allocation, Currency $currency): Money
    {
        return array_reduce(
            $allocation,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero($currency),
        );
    }

    // ------------------------------------------------- criterion 7 dies here

    /**
     * E10 credits BHD 10.000 "as three equal instalments". At three decimal places that is
     * impossible — 10.000 / 3 = 3.333… and no three equal amounts sum back to 10.000. The
     * residue of one thousandth goes to the first instalment.
     */
    public function testSplitsTheAssessmentsTenBhdIntoThreeInstalments(): void
    {
        $allocation = Allocator::intoEqualParts(Money::of('10.000', Currency::BHD), 3);

        self::assertSame(['3.334', '3.333', '3.333'], self::formatAll($allocation));
        self::assertSame('10.000', self::sum($allocation, Currency::BHD)->format());
    }

    /**
     * Acceptance criterion 7 claims each instalment must be BHD 3.334. Three of those sum to
     * 10.002, overpaying the credit by 0.002 — so the criterion cannot be satisfied without
     * breaking the total. This test is the refusal, made executable. See REJECTED.md.
     */
    public function testCriterionSevensUniformInstalmentsOverpayTheCredit(): void
    {
        $credit = Money::of('10.000', Currency::BHD);
        $asCriterionSevenClaims = Money::of('3.334', Currency::BHD);

        $total = $asCriterionSevenClaims
            ->plus($asCriterionSevenClaims)
            ->plus($asCriterionSevenClaims);

        self::assertSame('10.002', $total->format());
        self::assertFalse($total->equals($credit), 'criterion 7 would balance, but it does not');
        self::assertSame('0.002', $total->minus($credit)->format());

        // What we do instead sums exactly.
        self::assertTrue(
            self::sum(Allocator::intoEqualParts($credit, 3), Currency::BHD)->equals($credit),
        );
    }

    // ------------------------------------------------- general behaviour

    public function testSplitsEvenlyWhenTheAmountDivides(): void
    {
        $allocation = Allocator::intoEqualParts(Money::of('10.000', Currency::BHD), 2);

        self::assertSame(['5.000', '5.000'], self::formatAll($allocation));
    }

    public function testSinglePartReturnsTheWholeAmount(): void
    {
        $allocation = Allocator::intoEqualParts(Money::of('1200.00', Currency::AED), 1);

        self::assertSame(['1200.00'], self::formatAll($allocation));
    }

    public function testResidueGoesToTheEarliestParts(): void
    {
        // 5 minor units across 3 parts: 2, 2, 1.
        $allocation = Allocator::intoEqualParts(Money::ofMinor(5, Currency::AED), 3);

        self::assertSame([2, 2, 1], array_map(static fn (Money $m): int => $m->minor, $allocation));
    }

    public function testAllocatesAmountsSmallerThanThePartCount(): void
    {
        $allocation = Allocator::intoEqualParts(Money::ofMinor(2, Currency::AED), 5);

        self::assertSame([1, 1, 0, 0, 0], array_map(static fn (Money $m): int => $m->minor, $allocation));
        self::assertSame(2, self::sum($allocation, Currency::AED)->minor);
    }

    public function testAllocatesZero(): void
    {
        $allocation = Allocator::intoEqualParts(Money::zero(Currency::AED), 3);

        self::assertSame(['0.00', '0.00', '0.00'], self::formatAll($allocation));
    }

    /** A negative amount distributes its residue the same way, rather than inheriting
     *  intdiv()'s truncation toward zero. */
    public function testAllocatesNegativeAmountsSymmetrically(): void
    {
        $allocation = Allocator::intoEqualParts(Money::of('-10.000', Currency::BHD), 3);

        self::assertSame(['-3.334', '-3.333', '-3.333'], self::formatAll($allocation));
        self::assertSame('-10.000', self::sum($allocation, Currency::BHD)->format());
    }

    // ------------------------------------------------- invariants

    /** @return iterable<string, array{int, int, Currency}> */
    public static function allocations(): iterable
    {
        foreach ([Currency::AED, Currency::BHD] as $currency) {
            foreach ([0, 1, 2, 5, 7, 100, 999, 1000, 10000, 123457] as $minor) {
                foreach ([1, 2, 3, 4, 7, 12] as $parts) {
                    yield sprintf('%s %d/%d', $currency->value, $minor, $parts)
                        => [$minor, $parts, $currency];
                }
            }
        }
    }

    /**
     * The invariant that makes allocation trustworthy: the parts always sum back to exactly
     * the amount, and never differ from one another by more than a single minor unit.
     */
    #[DataProvider('allocations')]
    public function testPartsAlwaysSumToTheOriginalAndDifferByAtMostOneMinorUnit(
        int $minor,
        int $parts,
        Currency $currency,
    ): void {
        $amount = Money::ofMinor($minor, $currency);
        $allocation = Allocator::intoEqualParts($amount, $parts);

        self::assertCount($parts, $allocation);
        self::assertTrue(
            self::sum($allocation, $currency)->equals($amount),
            'allocation must sum to the original amount',
        );

        $minors = array_map(static fn (Money $m): int => $m->minor, $allocation);
        self::assertLessThanOrEqual(1, max($minors) - min($minors));
    }

    public function testRefusesZeroParts(): void
    {
        $this->expectException(InvalidAllocation::class);

        Allocator::intoEqualParts(Money::of('10.000', Currency::BHD), 0);
    }

    public function testRefusesNegativeParts(): void
    {
        $this->expectException(InvalidAllocation::class);

        Allocator::intoEqualParts(Money::of('10.000', Currency::BHD), -3);
    }
}
