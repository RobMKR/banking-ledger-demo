<?php

declare(strict_types=1);

namespace Ledger\Domain\Event\Exception;

use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventOutcome;

/**
 * The DecisionLog exists so that nothing is ever silently dropped. A decision with no reason
 * would be exactly that — a record that an event was refused, with no way to find out why.
 */
final class UnexplainedDecision extends EventException
{
    public static function about(EventId $event, EventOutcome $outcome): self
    {
        return new self(sprintf(
            'The decision to record %s as %s carries no reason. Every outcome must explain '
            . 'itself; that is what the log is for.',
            $event->value,
            $outcome->value,
        ));
    }
}
