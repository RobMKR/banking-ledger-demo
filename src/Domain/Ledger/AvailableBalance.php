<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Money\Money;

/**
 * Ledger balance minus active holds.
 *
 * The brief defines it in one line: "the account's available balance — ledger balance minus
 * active holds". The only thing left to decide is *which* ledger balance, since this ledger
 * has two date dimensions and therefore several.
 *
 * Available balance is a question about now. Both coordinates are the current day: what the
 * account is worth for value dates up to today, as far as the ledger knows today. Asking what
 * was available on Day 2 as known on Day 5 is a coherent question, but it is not the one an
 * authorization asks — an authorization is decided against the money that is actually there
 * when it arrives, and the ledger cannot decline it using facts it will only learn later.
 *
 * Both halves of the subtraction honour that day. The balance was always filtered; the
 * holds were not, so a hold placed on Day 5 used to reduce what the account had available
 * on Day 2 — a day-scoped figure minus an unscoped one. Collapsing the dimensions on the
 * hold side is the same error as collapsing them on the balance side.
 *
 * That choice is what makes Auth-B decline. E7 is booked on Day 5 carrying value_date Day 2,
 * so by the time E8 arrives on Day 5 the ledger *does* know about it, and available is
 * -155.00 rather than the +465.00 it stood at when Day 4 closed.
 */
final readonly class AvailableBalance
{
    public function __construct(
        private Ledger $ledger,
        private HoldRegistry $holds,
    ) {
    }

    public function on(AccountId $account, LedgerDay $day): Money
    {
        $held = $this->ledger->account($account);

        return $this->ledger->balanceAsOf($account, $day, $day)
            ->minus($this->holds->totalHeldFor($held, $day));
    }
}
