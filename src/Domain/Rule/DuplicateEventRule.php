<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Event\ProcessedEvents;

/**
 * The gate every event passes through before anything else looks at it.
 *
 * It runs first by design — before validation, before any rule, before the Ledger or the
 * HoldRegistry are touched. A duplicate must be refused on the strength of its id alone, not
 * because some later check happened to trip over it: E1 arriving a second time on Day 6 would
 * otherwise be refused by the ledger's backdated-booking guard, which is the right outcome
 * reached for entirely the wrong reason, and would stop being the outcome the moment the
 * duplicate happened to arrive in order.
 */
final readonly class DuplicateEventRule
{
    public function __construct(private ProcessedEvents $processed)
    {
    }

    /**
     * Admit an event for processing, or return the Decision that refuses it.
     *
     * Null means admitted. The id is recorded as part of admitting, so the check and the
     * record cannot come apart at a call site.
     */
    public function admit(LedgerEvent $event): ?Decision
    {
        if ($this->processed->remember($event->id)) {
            return null;
        }

        return Decision::about(
            $event,
            EventOutcome::REJECTED_DUPLICATE_EVENT_ID,
            sprintf(
                'Event %s was already processed in this replay; nothing is posted a second time.',
                $event->id->value,
            ),
        );
    }
}
