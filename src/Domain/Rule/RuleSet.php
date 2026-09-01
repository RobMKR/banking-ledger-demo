<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\AuthorizationEvent;
use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\DebitEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Event\SettlementEvent;

/**
 * One rule per kind of event, and the table that picks between them.
 *
 * The dispatch lives here rather than in the engine because it belongs next to the rules it
 * dispatches to: adding an event type means adding a rule and an arm, in one file, and the
 * compiler will not let you forget the second.
 *
 * The match is deliberately exhaustive with no default arm. A new LedgerEvent subclass with no
 * rule raises \UnhandledMatchError rather than being silently ignored — a loud failure at the
 * moment of the mistake, which is the right trade for something that would otherwise drop
 * events on the floor.
 *
 * The duplicate gate is not here. It is a gate, not a handler: it runs before dispatch, decides
 * nothing about what an event *means*, and applies identically to all five kinds.
 */
final readonly class RuleSet
{
    public function __construct(
        public CreditRule $credits,
        public DebitRule $debits,
        public AuthorizationRule $authorizations,
        public SettlementRule $settlements,
        public ReversalRule $reversals,
    ) {
    }

    public function applyTo(LedgerEvent $event): Decision
    {
        return match (true) {
            $event instanceof CreditEvent => $this->credits->apply($event),
            $event instanceof DebitEvent => $this->debits->apply($event),
            $event instanceof AuthorizationEvent => $this->authorizations->apply($event),
            $event instanceof SettlementEvent => $this->settlements->apply($event),
            $event instanceof ReversalEvent => $this->reversals->apply($event),
        };
    }
}
