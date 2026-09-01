<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Event\Exception\UnexplainedDecision;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;

/**
 * What happened to one event, and why.
 *
 * Deliberately does not carry the entries it produced. Those live in the Ledger, joined back
 * by LedgerEntry::$reference, and holding a second copy here would create two records that
 * could disagree about what was posted. The log answers "what did the engine do with E6"; the
 * ledger answers "what is in the account".
 */
final readonly class Decision
{
    private function __construct(
        public EventId $event,
        public EventType $eventType,
        public AccountId $account,
        public LedgerDay $day,
        public EventOutcome $outcome,
        public string $reason,
    ) {
    }

    /** @throws UnexplainedDecision the reason is empty */
    public static function about(LedgerEvent $event, EventOutcome $outcome, string $reason): self
    {
        if (trim($reason) === '') {
            throw UnexplainedDecision::about($event->id, $outcome);
        }

        return new self(
            $event->id,
            $event->type(),
            $event->account,
            $event->day,
            $outcome,
            trim($reason),
        );
    }

    public function isRejection(): bool
    {
        return $this->outcome->isRejection();
    }

    /** "E6  Day 4  REJECTED_ORPHAN_SETTLEMENT  <reason>" */
    public function describe(): string
    {
        return sprintf(
            '%s  %s  %s  %s',
            $this->event->value,
            (string) $this->day,
            $this->outcome->value,
            $this->reason,
        );
    }
}
