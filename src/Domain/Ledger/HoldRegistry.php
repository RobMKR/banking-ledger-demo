<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\DuplicateAuthorization;
use Ledger\Domain\Ledger\Exception\UnknownAuthorization;
use Ledger\Domain\Money\Money;

/**
 * Every hold the replay has placed, live or released, keyed by authorization id.
 *
 * Deliberately separate from the Ledger. The Ledger holds entries that move money; holds move
 * none. Merging them would make it possible to write a hold into the balance by accident,
 * which is the precise error criterion 5 is testing for.
 *
 * Not append-only, and it does not need to be: releasing swaps in a Hold that carries both
 * its placement and its release, so the replaced instance held nothing the new one lacks.
 * Append-only is a guarantee about the financial record, not about every index in the process.
 */
final class HoldRegistry
{
    /** @var array<string, Hold> */
    private array $holds = [];

    /** @throws DuplicateAuthorization */
    public function place(Hold $hold): void
    {
        if (isset($this->holds[$hold->authorization->value])) {
            throw DuplicateAuthorization::named($hold->authorization);
        }

        $this->holds[$hold->authorization->value] = $hold;
    }

    /**
     * Release the hold for $authorization and return it in its released form.
     *
     * @throws UnknownAuthorization  nothing was ever held under that id — E6's case
     * @throws Exception\HoldAlreadyReleased
     */
    public function release(AuthorizationId $authorization, LedgerDay $on): Hold
    {
        $released = $this->find($authorization)->released($on);
        $this->holds[$authorization->value] = $released;

        return $released;
    }

    public function has(AuthorizationId $authorization): bool
    {
        return isset($this->holds[$authorization->value]);
    }

    /** @throws UnknownAuthorization */
    public function find(AuthorizationId $authorization): Hold
    {
        return $this->holds[$authorization->value]
            ?? throw UnknownAuthorization::named($authorization);
    }

    /**
     * Holds live on an account, optionally as of a given day.
     *
     * Without $on this is "live now", which is what the replay asks as it walks forward. With
     * it, the question becomes bitemporal and a hold placed later does not count — see
     * Hold::isActiveOn().
     *
     * @return list<Hold>
     */
    public function activeFor(AccountId $account, ?LedgerDay $on = null): array
    {
        return array_values(array_filter(
            $this->holds,
            static fn (Hold $hold): bool => $hold->belongsTo($account)
                && ($on === null ? $hold->isActive() : $hold->isActiveOn($on)),
        ));
    }

    /** The sum of everything reserved on an account, now or as of $on. Zero when nothing is. */
    public function totalHeldFor(Account $account, ?LedgerDay $on = null): Money
    {
        $total = Money::zero($account->currency);

        foreach ($this->activeFor($account->id, $on) as $hold) {
            $total = $total->plus($hold->amount);
        }

        return $total;
    }

    /** Every hold ever placed, in placement order, released ones included. @return list<Hold> */
    public function all(): array
    {
        return array_values($this->holds);
    }

    public function count(): int
    {
        return count($this->holds);
    }
}
