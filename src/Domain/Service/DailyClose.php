<?php

declare(strict_types=1);

namespace Ledger\Domain\Service;

use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\OverdraftFeeRule;

/**
 * What happens when a day ends.
 *
 * Fees are assessed here rather than as events are processed, because the rule is about a
 * day's *closing* balance — assessing mid-day would charge on a figure the day never ended at.
 * It also matters for Auth-B: E8 is decided against -155.00 on Day 5 and the three fees land
 * afterwards, so no ambiguity in the fee reading can reach back and change that decline.
 *
 * The fee is stated in AED and only ACC-001 ever overdraws. Accounts in another currency are
 * skipped rather than charged a converted figure the brief never gives — an invented exchange
 * rate would be a worse answer than none. Unreachable in this stream; the guard exists so the
 * loop over all accounts cannot crash on a case the brief leaves undefined.
 */
final readonly class DailyClose
{
    public function __construct(
        private Ledger $ledger,
        private OverdraftFeeRule $fees,
        private Money $fee,
    ) {
    }

    /** @return list<LedgerEntry> every fee raised by this close, across all accounts */
    public function close(LedgerDay $day): array
    {
        $raised = [];

        foreach ($this->ledger->accounts() as $account) {
            if ($account->currency !== $this->fee->currency) {
                continue;
            }

            foreach ($this->fees->assessThrough($account->id, $day) as $entry) {
                $raised[] = $entry;
            }
        }

        return $raised;
    }
}
