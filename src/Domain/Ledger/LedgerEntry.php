<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\MisdirectedEntry;
use Ledger\Domain\Money\Money;

/**
 * One immutable financial record.
 *
 * Carries two independent dates. `valueDate` is the day the money belongs to — the dimension
 * the fee rule reads when it asks for "all entries with value_date <= that day". `bookedDay`
 * is the day the ledger learned of the entry. They are usually the same; E7 and E9 are in the
 * brief precisely because they are not.
 *
 * Amounts are stored signed so that a balance is a plain sum. The debit-direction named
 * constructors take a positive figure and negate it, keeping the sign convention in one place.
 */
final readonly class LedgerEntry
{
    private function __construct(
        public AccountId $account,
        public EntryType $type,
        public Money $amount,
        public LedgerDay $valueDate,
        public LedgerDay $bookedDay,
        public ?string $reference,
    ) {
        if (!$type->permits($amount)) {
            throw MisdirectedEntry::wrongSign($type, $amount);
        }
    }

    public static function credit(
        AccountId $account,
        Money $amount,
        LedgerDay $valueDate,
        LedgerDay $bookedDay,
        ?string $reference = null,
    ): self {
        return new self($account, EntryType::CREDIT, $amount, $valueDate, $bookedDay, $reference);
    }

    public static function debit(
        AccountId $account,
        Money $amount,
        LedgerDay $valueDate,
        LedgerDay $bookedDay,
        ?string $reference = null,
    ): self {
        return new self($account, EntryType::DEBIT, $amount->negated(), $valueDate, $bookedDay, $reference);
    }

    public static function settlement(
        AccountId $account,
        Money $amount,
        LedgerDay $valueDate,
        LedgerDay $bookedDay,
        ?string $reference = null,
    ): self {
        return new self($account, EntryType::SETTLEMENT, $amount->negated(), $valueDate, $bookedDay, $reference);
    }

    public static function overdraftFee(
        AccountId $account,
        Money $amount,
        LedgerDay $valueDate,
        LedgerDay $bookedDay,
        ?string $reference = null,
    ): self {
        return new self($account, EntryType::OVERDRAFT_FEE, $amount->negated(), $valueDate, $bookedDay, $reference);
    }

    public static function interest(
        AccountId $account,
        Money $amount,
        LedgerDay $valueDate,
        LedgerDay $bookedDay,
        ?string $reference = null,
    ): self {
        return new self($account, EntryType::INTEREST, $amount, $valueDate, $bookedDay, $reference);
    }

    /**
     * The compensating entry for an earlier one.
     *
     * It does not remove the original — nothing ever does. It appends the inverse, which is
     * why the ledger can end up holding a charge the final balance no longer justifies.
     */
    public static function reversalOf(
        LedgerEntry $original,
        LedgerDay $bookedDay,
        ?string $reference = null,
    ): self {
        return new self(
            $original->account,
            EntryType::REVERSAL,
            $original->amount->negated(),
            $original->valueDate,
            $bookedDay,
            $reference,
        );
    }

    /** True when this entry counts toward the balance of $valueDate as known at $knownAsOf. */
    public function countsToward(LedgerDay $valueDate, LedgerDay $knownAsOf): bool
    {
        return $this->valueDate->isOnOrBefore($valueDate)
            && $this->bookedDay->isOnOrBefore($knownAsOf);
    }

    public function belongsTo(AccountId $account): bool
    {
        return $this->account->equals($account);
    }

    /** True when the entry's value date precedes the day it was booked on. */
    public function isBackdated(): bool
    {
        return $this->valueDate->isBefore($this->bookedDay);
    }
}
