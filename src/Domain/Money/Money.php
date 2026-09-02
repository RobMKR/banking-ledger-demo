<?php

declare(strict_types=1);

namespace Ledger\Domain\Money;

use Ledger\Domain\Money\Exception\ArithmeticOverflow;
use Ledger\Domain\Money\Exception\CurrencyMismatch;
use Ledger\Domain\Money\Exception\InvalidAmount;

/**
 * An exact monetary amount, stored as a signed integer count of minor units.
 *
 * No floats anywhere. AED 1,200.00 is the integer 120000; BHD 10.000 is the integer 10000.
 * 64-bit ints give roughly 9.2e16 AED and 9.2e15 BHD of headroom, some fourteen orders of
 * magnitude beyond anything this ledger holds, so arbitrary-precision arithmetic (GMP,
 * BCMath) would add a runtime extension to solve a problem that does not exist here.
 *
 * The risk that does exist is silent int-to-float promotion on overflow, which PHP performs
 * without warning. Every arithmetic path guards against it explicitly.
 */
final readonly class Money implements \Stringable
{
    private function __construct(
        public int      $minor,
        public Currency $currency,
    )
    {
    }

    /** Construct from a count of minor units: fils for AED, thousandths for BHD. */
    public static function ofMinor(int $minor, Currency $currency): self
    {
        // abs(PHP_INT_MIN) is not representable, so negation and formatting would both break.
        if ($minor === PHP_INT_MIN) {
            throw ArithmeticOverflow::inOperation('ofMinor');
        }

        return new self($minor, $currency);
    }

    /**
     * Construct from a decimal string such as "1200.00" or "-950.00".
     *
     * A string, never a float: 0.1 + 0.2 is the reason this whole class exists. Parsing is
     * pure string manipulation, so no value ever passes through a binary floating-point
     * representation. Input carrying more decimals than the currency supports is refused
     * rather than rounded — see InvalidAmount::tooPrecise().
     */
    public static function of(string $amount, Currency $currency): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $matches) !== 1) {
            throw InvalidAmount::malformed($amount);
        }

        [, $sign, $whole] = $matches;
        $fraction = $matches[3] ?? '';
        $exponent = $currency->exponent();

        if (strlen($fraction) > $exponent) {
            throw InvalidAmount::tooPrecise($amount, $currency);
        }

        $digits = $whole . str_pad($fraction, $exponent, '0');

        // Round-tripping through string is an exact overflow check: a value beyond the
        // integer range will not cast back to the same digits.
        $normalised = ltrim($digits, '0');
        if ($normalised === '') {
            $normalised = '0';
        }

        if (strlen($normalised) > strlen((string)PHP_INT_MAX)) {
            throw ArithmeticOverflow::forAmount($amount);
        }

        $minor = (int)$normalised;
        if ((string)$minor !== $normalised) {
            throw ArithmeticOverflow::forAmount($amount);
        }

        return self::ofMinor($sign === '-' ? -$minor : $minor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        $sum = $this->minor + $other->minor;
        if (!is_int($sum)) {
            throw ArithmeticOverflow::inOperation('plus');
        }

        return self::ofMinor($sum, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        $difference = $this->minor - $other->minor;
        if (!is_int($difference)) {
            throw ArithmeticOverflow::inOperation('minus');
        }

        return self::ofMinor($difference, $this->currency);
    }

    /**
     * Apply a rate, rounding half-up to this currency's precision.
     *
     * The rate is an integer rational, so the multiplication is exact and the single
     * rounding happens in Rounding::divideHalfUp() — the one named boundary where
     * precision is allowed to be lost.
     */
    public function multipliedBy(Rate $rate): self
    {
        $product = $this->minor * $rate->numerator();
        if (!is_int($product)) {
            throw ArithmeticOverflow::inOperation('multipliedBy');
        }

        return self::ofMinor(
            Rounding::divideHalfUp($product, $rate->denominator()),
            $this->currency,
        );
    }

    public function negated(): self
    {
        return self::ofMinor(-$this->minor, $this->currency);
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /** Returns -1, 0 or 1 as this is less than, equal to, or greater than $other. */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    /** Value equality, including currency. Money of different currencies is never equal. */
    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /** Renders at the currency's own precision: "1200.00" for AED, "10.000" for BHD. */
    public function format(): string
    {
        $exponent = $this->currency->exponent();
        $sign = $this->minor < 0 ? '-' : '';

        $digits = str_pad((string)abs($this->minor), $exponent + 1, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    public function __toString(): string
    {
        return $this->format() . ' ' . $this->currency->value;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
