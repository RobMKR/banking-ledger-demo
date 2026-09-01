<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Service\InterestSchedule;

/**
 * Posts the one credit the whole window's interest capitalizes into.
 *
 * "Accruals capitalize as a single credit at end of Day 6" — one entry, not six. The daily
 * figures are a schedule, not entries, which is what makes restating them against final
 * knowledge violate nothing: an accrual that was never booked cannot be mutated, so the
 * append-only rule has nothing to say about it. See AMBIGUITIES.md §4.
 *
 * The credit carries value_date Day 6. It cannot be spread back across the days that earned it
 * without changing those days' closing balances, which would change the accruals, which would
 * change the credit — the Day 6 circularity of §8. Capitalizing on one day at the end cuts it.
 *
 * An account that earned nothing gets no entry at all. A zero-value credit would be a record of
 * something that did not happen.
 */
final readonly class InterestAccrualRule
{
    public function __construct(
        private Ledger $ledger,
        private InterestSchedule $schedule,
    ) {
    }

    /** @return LedgerEntry|null the capitalizing credit, or null when nothing accrued */
    public function capitalize(AccountId $account, LedgerDay $finalDay): ?LedgerEntry
    {
        if ($this->alreadyCapitalized($account)) {
            return null;
        }

        $total = $this->schedule->totalFor($account, $finalDay);

        if (!$total->isPositive()) {
            return null;
        }

        $entry = LedgerEntry::interest($account, $total, $finalDay, $finalDay, 'INTEREST');
        $this->ledger->append($entry);

        return $entry;
    }

    private function alreadyCapitalized(AccountId $account): bool
    {
        return $this->ledger->entriesOfType($account, EntryType::INTEREST) !== [];
    }
}
