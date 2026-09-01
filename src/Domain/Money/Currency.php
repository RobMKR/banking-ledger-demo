<?php

declare(strict_types=1);

namespace Ledger\Domain\Money;

/**
 * The currencies this ledger handles, with their ISO 4217 minor-unit exponent.
 *
 * An enum rather than an open value object: the brief fixes the set at exactly two, and
 * an enum makes an unsupported currency unconstructable rather than merely unexpected.
 */
enum Currency: string
{
    case AED = 'AED';
    case BHD = 'BHD';

    /** Number of decimal places — the power of ten between a major unit and a minor unit. */
    public function exponent(): int
    {
        return match ($this) {
            self::AED => 2,
            self::BHD => 3,
        };
    }

    /** Minor units in one major unit: 100 for AED, 1000 for BHD. */
    public function minorUnitScale(): int
    {
        return 10 ** $this->exponent();
    }
}
