<?php

declare(strict_types=1);

namespace Ledger\Domain\Money;

use Ledger\Domain\Money\Exception\ArithmeticOverflow;
use Ledger\Domain\Money\Exception\DivisionByZero;

/**
 * The one place rounding is allowed to happen.
 *
 * Rounding is a named boundary, not an incidental side effect: money is parsed exactly,
 * added exactly, and only ever loses precision here. Keeping it in a single function means
 * every rounding decision in the ledger is greppable.
 *
 * HALF_UP is read as "half away from zero", matching Java's RoundingMode.HALF_UP and PHP's
 * own round(). It is not load-bearing for this dataset — no accrual in the assessment lands
 * on an exact half, which RoundingTest proves — but the choice is explicit rather than
 * inherited. See NUMBERS.md.
 */
final class Rounding
{
    /**
     * Integer division rounding halves away from zero, with no floating point anywhere.
     *
     * The identity is intdiv(2n + d, 2d): doubling both sides turns "is the remainder at
     * least half the divisor?" into a plain integer comparison.
     */
    public static function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw DivisionByZero::inRounding();
        }

        // abs(PHP_INT_MIN) is not representable, so the sign split below would silently wrap.
        if ($numerator === PHP_INT_MIN || $denominator === PHP_INT_MIN) {
            throw ArithmeticOverflow::inOperation('divideHalfUp');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $absNumerator = abs($numerator);
        $absDenominator = abs($denominator);

        $doubledNumerator = 2 * $absNumerator;
        $doubledDenominator = 2 * $absDenominator;
        if (!is_int($doubledNumerator) || !is_int($doubledDenominator)) {
            throw ArithmeticOverflow::inOperation('divideHalfUp');
        }

        $shifted = $doubledNumerator + $absDenominator;
        if (!is_int($shifted)) {
            throw ArithmeticOverflow::inOperation('divideHalfUp');
        }

        $quotient = intdiv($shifted, $doubledDenominator);

        return $negative ? -$quotient : $quotient;
    }

    /**
     * True when the division sits exactly on a half — the only case where the choice
     * between HALF_UP, HALF_DOWN and HALF_EVEN changes the answer.
     *
     * Exposed so tests can assert the assessment dataset never hits one, which is what makes
     * the tie-breaking rule documentable as "not load-bearing here" rather than defended.
     */
    public static function landsOnATie(int $numerator, int $denominator): bool
    {
        if ($denominator === 0) {
            throw DivisionByZero::inRounding();
        }

        $remainder = abs($numerator % $denominator);

        return $remainder * 2 === abs($denominator);
    }
}
