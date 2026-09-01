<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Money\Money;

/**
 * What a ledger entry represents.
 *
 * Amounts are stored signed, so the balance is a plain sum. The type is not what makes an
 * entry negative — it is what makes a wrongly-signed entry detectable, via permits().
 */
enum EntryType: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
    case SETTLEMENT = 'SETTLEMENT';
    case OVERDRAFT_FEE = 'OVERDRAFT_FEE';
    case INTEREST = 'INTEREST';
    case REVERSAL = 'REVERSAL';

    /** True when an amount of this sign is coherent for this type of entry. */
    public function permits(Money $amount): bool
    {
        return match ($this) {
            self::CREDIT, self::INTEREST => !$amount->isNegative(),
            self::DEBIT, self::SETTLEMENT, self::OVERDRAFT_FEE => !$amount->isPositive(),
            // A reversal takes the sign of whatever it undoes, so either direction is valid.
            self::REVERSAL => true,
        };
    }

    /** Entries the ledger raises itself, rather than ones the event stream asks for. */
    public function isSystemGenerated(): bool
    {
        return $this === self::OVERDRAFT_FEE || $this === self::INTEREST;
    }
}
