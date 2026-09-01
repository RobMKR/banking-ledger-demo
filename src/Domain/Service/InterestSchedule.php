<?php

declare(strict_types=1);

namespace Ledger\Domain\Service;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Money\Rate;

/**
 * "Daily interest: 0.04% per day on the closing ledger balance, positive balances only.
 * Accruals capitalize as a single credit at end of Day 6. The rounded daily accruals must sum
 * exactly to the capitalized total."
 *
 * That last sentence is the interesting one, and it is satisfied by construction rather than by
 * reconciliation: the capitalized figure *is* the sum of the rounded dailies. Round each day,
 * then add. Rounding a separately-computed total is the mistake the rule is written against,
 * and it is what criterion 8 — "the remainder is discarded" — would require.
 *
 * **Accruals are restated against final knowledge** (AMBIGUITIES.md §4). Every day's balance is
 * read as known at the close of the window, so E7's backdated debit and E9's reversal have both
 * landed before a single accrual is computed. The rejected reading is *as-known*, accruing
 * against each day's knowledge at its own close, which is closer to how a bank accrues nightly
 * but books a Day 5 accrual the final ledger contradicts. This is the one decision of the four
 * held with lower confidence, and §4 says so: the account genuinely was without that money from
 * Day 5 until Day 6 — it was declined an authorization over it and charged three fees on it —
 * so paying interest as though E7 never happened is not obviously right.
 *
 * Nothing is computed on a balance that already includes interest. The schedule is built from
 * the pre-capitalization ledger and posted once, which is how the Day 6 circularity in §8 is
 * avoided rather than iterated away.
 */
final readonly class InterestSchedule
{
    public function __construct(
        private Ledger $ledger,
        private Rate $rate,
    ) {
    }

    /**
     * The rounded accrual for every day in the window, keyed by day number.
     *
     * Days that close at or below zero accrue nothing. "Positive balances only" is exact: a
     * balance of zero is not positive, so it earns nothing rather than earning a rounded zero.
     *
     * @return array<int, Money>
     */
    public function accrualsFor(AccountId $account, LedgerDay $through): array
    {
        $currency = $this->ledger->account($account)->currency;
        $accruals = [];

        foreach (LedgerDay::through(1, $through->number) as $day) {
            $closing = $this->ledger->balanceAsOf($account, $day, $through);

            $accruals[$day->number] = $closing->isPositive()
                ? $closing->multipliedBy($this->rate)
                : Money::zero($currency);
        }

        return $accruals;
    }

    /** The capitalized total: the sum of the rounded dailies, never a rounding of the sum. */
    public function totalFor(AccountId $account, LedgerDay $through): Money
    {
        $total = Money::zero($this->ledger->account($account)->currency);

        foreach ($this->accrualsFor($account, $through) as $accrual) {
            $total = $total->plus($accrual);
        }

        return $total;
    }
}
