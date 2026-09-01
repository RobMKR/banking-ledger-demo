<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\BackdatedBooking;
use Ledger\Domain\Ledger\Exception\UnknownAccount;
use Ledger\Domain\Money\Money;

/**
 * An append-only, in-memory record of financial entries.
 *
 * Append-only is structural rather than promised: there is no remove, no replace, and every
 * LedgerEntry is readonly. The only way the ledger changes is by growing.
 *
 * The ledger is bitemporal. Every entry carries the day the money belongs to and the day the
 * ledger learned of it, and balanceAsOf() takes both. "Day 2's closing balance" is not one
 * number: it is −370.00 as known at the close of Day 5, and 250.00 once E9 arrives on Day 6.
 * Both are correct; they answer different questions.
 */
final class Ledger
{
    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var list<LedgerEntry> */
    private array $entries = [];

    private ?LedgerDay $highestBookedDay = null;

    public function __construct(Account ...$accounts)
    {
        foreach ($accounts as $account) {
            $this->accounts[$account->id->value] = $account;
        }
    }

    /**
     * Record an entry. The only mutating operation on this class.
     *
     * @throws UnknownAccount        the ledger does not hold that account
     * @throws BackdatedBooking      the entry would be booked before something already booked
     */
    public function append(LedgerEntry $entry): void
    {
        $account = $this->account($entry->account);
        $account->assertHolds($entry->amount);

        // A backdated value date is legitimate — E7 does exactly that, and the whole exercise
        // turns on it. A backdated *booking* would rewrite what the ledger already knew.
        if ($this->highestBookedDay !== null && $entry->bookedDay->isBefore($this->highestBookedDay)) {
            throw BackdatedBooking::before($entry->bookedDay, $this->highestBookedDay);
        }

        $this->entries[] = $entry;
        $this->highestBookedDay = $entry->bookedDay;
    }

    /**
     * The balance of $account for value date $valueDate, as the ledger knew it at $knownAsOf.
     *
     * Both dates are required, always. There is deliberately no single-argument convenience
     * that defaults $knownAsOf to "now": collapsing the two dimensions is the one mistake that
     * silently produces a plausible-looking wrong answer, and the API refuses to make it easy.
     */
    public function balanceAsOf(AccountId $account, LedgerDay $valueDate, LedgerDay $knownAsOf): Money
    {
        $balance = $this->account($account)->openingBalance;

        foreach ($this->entries as $entry) {
            if ($entry->belongsTo($account) && $entry->countsToward($valueDate, $knownAsOf)) {
                $balance = $balance->plus($entry->amount);
            }
        }

        return $balance;
    }

    /**
     * Entries for one account, optionally limited to what was known at a given day.
     *
     * @return list<LedgerEntry>
     */
    public function entriesFor(AccountId $account, ?LedgerDay $knownAsOf = null): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (LedgerEntry $entry): bool => $entry->belongsTo($account)
                && ($knownAsOf === null || $entry->bookedDay->isOnOrBefore($knownAsOf)),
        ));
    }

    /** Every entry, in the order it was appended. @return list<LedgerEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function account(AccountId $id): Account
    {
        return $this->accounts[$id->value] ?? throw UnknownAccount::named($id);
    }

    /** @return list<Account> */
    public function accounts(): array
    {
        return array_values($this->accounts);
    }

    public function holds(AccountId $id): bool
    {
        return isset($this->accounts[$id->value]);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
