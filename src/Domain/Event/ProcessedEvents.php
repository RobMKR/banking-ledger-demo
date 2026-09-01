<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

/**
 * The ids this replay has already seen.
 *
 * Nothing in the brief asks for this. It is a deliberate addition, and it earns its place
 * because the ledger is append-only: a double-post cannot be deleted, only offset by a
 * compensating reversal — which is the exact mess criterion 6 is refused over. Meanwhile
 * every real event stream is at-least-once; retries and operator re-runs are routine.
 *
 * Stated limitations, since a guard that overstates its coverage is worse than none:
 *
 *  1. Ids only. A same-id-different-payload event is absorbed as an ordinary duplicate and
 *     the upstream integrity problem is never surfaced. Tested, so the limit is visible
 *     rather than assumed.
 *  2. Event ids only. A second authorization claiming a live *authorization* id is a
 *     different problem, guarded separately by HoldRegistry::place().
 *  3. In-memory, so this is scoped to a single replay. The brief bans persistence, so there
 *     is nowhere to keep it; a real deployment needs a durable idempotency key.
 *
 * It does not remember what the first sighting decided — that is the DecisionLog's job, and
 * DecisionLog::about() returns the first decision for exactly this reason.
 */
final class ProcessedEvents
{
    /** @var array<string, true> */
    private array $seen = [];

    /**
     * Record a sighting, and say whether it was the first.
     *
     * Deliberately one operation rather than a check followed by a record. Split in two, a
     * caller can test and then forget to record, and the failure mode of that mistake is a
     * silent double-post into a ledger that cannot delete it.
     */
    public function remember(EventId $id): bool
    {
        if (isset($this->seen[$id->value])) {
            return false;
        }

        $this->seen[$id->value] = true;

        return true;
    }

    /** A pure query, for reporting. Processing should use remember(). */
    public function hasSeen(EventId $id): bool
    {
        return isset($this->seen[$id->value]);
    }

    public function count(): int
    {
        return count($this->seen);
    }
}
