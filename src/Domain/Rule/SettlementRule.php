<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\SettlementEvent;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerEntry;

/**
 * An authorization coming good. E5 (Auth-A, 185.00) and E6 (Auth-Z, orphaned).
 *
 * A settlement is the event that actually moves the money an authorization only reserved. It
 * does two things at once and both must happen or neither: the entry is appended to the
 * ledger and the hold is released. Splitting them across call sites is how an account ends up
 * paying twice — once in the balance, once in a hold nobody let go of.
 *
 * **Criterion 4 lands here.** "Any settlement referencing an authorization ID not present in
 * the ledger must be rejected and the funds must not leave the account." Honoured literally:
 * E6 posts nothing and is recorded as REJECTED_ORPHAN_SETTLEMENT. In a card network that is
 * the wrong answer — a settlement without a preceding authorization is a force-post, the money
 * has already moved, and refusing it produces a reconciliation break rather than a saved
 * 180.00. The brief's criterion outranks industry practice, and the divergence is written up
 * in AMBIGUITIES.md §2 with the figures force-posting would have produced (final 210.69).
 *
 * Two smaller decisions, neither exercised by the stream:
 *
 *  - **A settlement closes the whole hold even when it settles for less.** Auth-A reserves
 *    200.00 and settles 185.00; the remaining 15.00 comes back rather than staying reserved.
 *  - **Settling for more than was held is allowed.** The hold is an estimate and the settled
 *    figure is what the network guarantees, so the entry follows the event, not the hold.
 *
 * Account existence and currency are not checked here. They are cross-cutting preconditions
 * that apply to every event type, and repeating them in five rules is how one gets missed;
 * they belong to a single pass in front of the dispatcher when the replay engine exists.
 */
final readonly class SettlementRule
{
    public function __construct(
        private Ledger $ledger,
        private HoldRegistry $holds,
    ) {
    }

    public function apply(SettlementEvent $event): Decision
    {
        if (!$this->holds->has($event->authorization)) {
            return $this->reject($event, EventOutcome::REJECTED_ORPHAN_SETTLEMENT, sprintf(
                'No authorization "%s" was ever issued on %s. Nothing is posted and the funds '
                . 'stay in the account.',
                $event->authorization->value,
                $event->account->value,
            ));
        }

        $hold = $this->holds->find($event->authorization);

        if (!$hold->belongsTo($event->account)) {
            return $this->reject($event, EventOutcome::REJECTED_INVALID_EVENT, sprintf(
                'Authorization "%s" is held against %s, not %s. Nothing is posted.',
                $event->authorization->value,
                $hold->account->value,
                $event->account->value,
            ));
        }

        if (!$hold->isActive()) {
            return $this->reject($event, EventOutcome::REJECTED_INVALID_EVENT, sprintf(
                'Authorization "%s" was already settled on %s. Nothing is posted a second time.',
                $event->authorization->value,
                (string) $hold->releasedOn,
            ));
        }

        $this->ledger->append(LedgerEntry::settlement(
            $event->account,
            $event->amount,
            $event->valueDate,
            $event->day,
            $event->id->value,
        ));
        $this->holds->release($event->authorization, $event->day);

        return Decision::about($event, EventOutcome::POSTED, $this->explainPosting($event, $hold));
    }

    private function explainPosting(SettlementEvent $event, Hold $hold): string
    {
        return sprintf(
            'Settled %s for %s at value_date %s; the %s hold is released.',
            $event->authorization->value,
            $event->amount->format(),
            (string) $event->valueDate,
            $hold->amount->format(),
        );
    }

    private function reject(SettlementEvent $event, EventOutcome $outcome, string $reason): Decision
    {
        return Decision::about($event, $outcome, $reason);
    }
}
