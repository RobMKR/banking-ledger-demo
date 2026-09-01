<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;

/**
 * One instruction from the stream.
 *
 * An event is not a ledger entry. It is a request that the engine may post, decline or
 * reject, and several events post no entry at all: an authorization only reserves, a declined
 * one does nothing, an orphan settlement is refused. Keeping the two apart is what lets the
 * DecisionLog record all ten events while the Ledger holds only the entries they produced.
 *
 * Every event carries two days, exactly as the brief writes them:
 *
 *   "E7 — Day 5 — DEBIT — ACC-001 AED 620.00 — value_date Day 2"
 *      ^ the day it is presented          ^ the day the money belongs to
 *
 * They are equal for eight of the ten. E7 and E9 are the two that are not, and the whole
 * exercise turns on them.
 *
 * An abstract class rather than an interface, deliberately. The five events share state, not
 * just behaviour: all four fields below are common to every one of them, and an interface
 * cannot declare promoted constructor properties, so each concrete type would redeclare all
 * four and one shape would live in five places. The set is closed — the brief fixes exactly
 * five kinds, nothing outside implements this, and the tests construct real events rather
 * than doubles, so there is no seam for an interface to serve. `readonly` settles it: it
 * propagates to every subclass as a parse-time error, which an interface cannot require, and
 * immutability is load-bearing throughout this ledger. If a second representation ever
 * appears — a stored form, a wire format — the interface earns its place then, because then
 * there are genuinely two implementations.
 */
abstract readonly class LedgerEvent
{
    protected function __construct(
        public EventId $id,
        public AccountId $account,
        public LedgerDay $day,
        public LedgerDay $valueDate,
    ) {
    }

    abstract public function type(): EventType;

    /** A one-line rendering for the log and the per-day report. */
    abstract public function describe(): string;

    /** True when the event belongs to a day earlier than the one it arrives on. */
    public function isBackdated(): bool
    {
        return $this->valueDate->isBefore($this->day);
    }
}
