<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerEntry;

/**
 * Undoing an earlier event. E9, which reverses E7.
 *
 * Under append-only nothing is removed. The reversal appends the inverse, so both entries
 * stand in the ledger for good and the *balance* returns to where it was while the *record*
 * grows. That distinction is the whole of criterion 6's refusal: after E9 the balances do come
 * back — Day 2 would read 250.00 again — but the three overdraft fees E7 caused were booked
 * records, no event in the stream reverses them, and no non-negotiable rule grants an
 * auto-reversal. So Day 2 closes at 225.00, not 250.00, and "all balances and fees return to
 * their pre-E7 values" is false. See REJECTED.md and AMBIGUITIES.md §3.
 *
 * The reversal carries the original's value date rather than the day it was raised. Otherwise
 * the pair would sit in two different days and net to zero on neither. E9 states value_date
 * Day 2 — matching E7 — so the brief agrees; the rule holds regardless of whether a stream
 * says so.
 */
final readonly class ReversalRule
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function apply(ReversalEvent $event): Decision
    {
        $original = $this->ledger->entryReferencing($event->reverses->value);

        if ($original === null) {
            return Decision::about($event, EventOutcome::REJECTED_INVALID_EVENT, sprintf(
                'There is nothing to reverse: %s posted no entry to this ledger.',
                $event->reverses->value,
            ));
        }

        if (!$original->belongsTo($event->account)) {
            return Decision::about($event, EventOutcome::REJECTED_INVALID_EVENT, sprintf(
                '%s posted to %s, not %s. Nothing is reversed.',
                $event->reverses->value,
                $original->account->value,
                $event->account->value,
            ));
        }

        if ($this->ledger->holdsAReversalOf($event->reverses->value)) {
            return Decision::about($event, EventOutcome::REJECTED_INVALID_EVENT, sprintf(
                '%s has already been reversed. Reversing it again would credit the account twice.',
                $event->reverses->value,
            ));
        }

        $this->ledger->append(LedgerEntry::reversalOf($original, $event->day, $event->id->value));

        return Decision::about($event, EventOutcome::POSTED, sprintf(
            'Reversed %s: %s at value_date %s. The original entry stands; nothing is deleted.',
            $event->reverses->value,
            $original->amount->negated()->format(),
            (string) $original->valueDate,
        ));
    }
}
