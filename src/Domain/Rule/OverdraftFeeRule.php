<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Money;

/**
 * "Overdraft fee: AED 25.00, assessed once per day per account when that day's closing ledger
 * balance (all entries with value_date <= that day) is negative. Booked with value_date equal
 * to the day assessed."
 *
 * **Assessment is retroactive.** When a backdated entry arrives, every day from its value date
 * onward is reassessed against what is now known. E7 lands on Day 5 carrying value_date Day 2,
 * which drags Day 2 to -370.00 and cascades through Day 4 and Day 5 — three fees, 75.00. The
 * rejected reading is *sealed*, where a day's fee decision is final once closed and only Day 5
 * is ever charged: one fee, 25.00. That is the single most load-bearing decision in this build
 * and it is worked through in AMBIGUITIES.md §1 rather than assumed here.
 *
 * **A single ascending pass is the fixpoint, and no iteration cap is needed.** A fee books with
 * value_date equal to the day whose balance was negative, so it can only ever affect days at or
 * after that day — never an earlier one. Walking D1 upward and recomputing each day's balance
 * as we go therefore settles in one sweep, and "once per day per account" bounds the result at
 * one fee per day. The convergence guard the earlier design carried would have been a runtime
 * assert against a loop that cannot occur; it is recorded in REJECTED.md as abandoned, and a
 * test proving a second pass is a no-op carries the claim instead.
 *
 * **Fees are never unwound.** Once booked, a fee is a record, and the append-only rule keeps it
 * whatever happens later. After E9 reverses E7, Day 2 closes at 225.00 rather than 250.00
 * because its fee stands. That is exactly what refutes criterion 6, and it is also what the
 * deliberate failing test exposes as the honest cost of this reading.
 *
 * The fee is given in AED and only ACC-001 ever overdraws. What a 25.00 AED fee means on a BHD
 * account is undefined by the brief and unreachable in this stream, so nothing here invents an
 * answer — the ledger's own currency guard would refuse it.
 */
final readonly class OverdraftFeeRule
{
    public function __construct(
        private Ledger $ledger,
        private Money $fee,
    ) {
    }

    /**
     * Assess every day up to and including $today against what the ledger knows today.
     *
     * @return list<LedgerEntry> the fees raised by this pass, in ascending day order
     */
    public function assessThrough(AccountId $account, LedgerDay $today): array
    {
        $raised = [];

        foreach (LedgerDay::through(1, $today->number) as $day) {
            if ($this->alreadyAssessed($account, $day)) {
                continue;
            }

            // Recomputed inside the loop, not hoisted: a fee raised on an earlier day is part
            // of the balance every later day is judged against. That is the cascade.
            if (!$this->ledger->balanceAsOf($account, $day, $today)->isNegative()) {
                continue;
            }

            $entry = LedgerEntry::overdraftFee(
                $account,
                $this->fee,
                $day,
                $today,
                sprintf('FEE-D%d', $day->number),
            );
            $this->ledger->append($entry);
            $raised[] = $entry;
        }

        return $raised;
    }

    /** Once per day per account — the brief's words, and the reason a second pass is a no-op. */
    private function alreadyAssessed(AccountId $account, LedgerDay $day): bool
    {
        foreach ($this->ledger->entriesOfType($account, EntryType::OVERDRAFT_FEE) as $fee) {
            if ($fee->valueDate->equals($day)) {
                return true;
            }
        }

        return false;
    }
}
