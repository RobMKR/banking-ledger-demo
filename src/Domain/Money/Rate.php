<?php

declare(strict_types=1);

namespace Ledger\Domain\Money;

use Ledger\Domain\Money\Exception\InvalidRate;

/**
 * A rate held as an exact integer rational, never as a float.
 *
 * The brief's daily interest rate is 0.04%. Written as 0.0004 it is not representable in
 * binary floating point; written as 4/10000 it is exact. Basis points are the natural unit:
 * one basis point is 0.01%, so 0.04% is exactly 4 bps.
 */
final readonly class Rate implements \Stringable
{
    /** One basis point is one ten-thousandth. */
    private const int BASIS_POINTS_PER_UNIT = 10_000;

    private function __construct(public int $basisPoints)
    {
    }

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw InvalidRate::negative($basisPoints);
        }

        return new self($basisPoints);
    }

    public function numerator(): int
    {
        return $this->basisPoints;
    }

    public function denominator(): int
    {
        return self::BASIS_POINTS_PER_UNIT;
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    /** Renders as a percentage: 4 bps becomes "0.04%". */
    public function __toString(): string
    {
        return sprintf(
            '%d.%02d%%',
            intdiv($this->basisPoints, 100),
            abs($this->basisPoints % 100),
        );
    }
}
