<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

/**
 * What the engine did with an event.
 *
 * The rejection cases are named for their cause rather than lumped under one ERROR, because
 * the brief asks the report to print errors and "rejected" on its own is not a reason. Two of
 * them are the load-bearing ones: ORPHAN_SETTLEMENT is E6, and DUPLICATE_EVENT_ID guards the
 * append-only ledger against a double-post that could then only be offset, never removed.
 */
enum EventOutcome: string
{
    /** Entries were written to the ledger. */
    case POSTED = 'POSTED';

    /** An authorization passed the available-balance test; a hold is live. */
    case APPROVED = 'APPROVED';

    /** An authorization failed it. No hold, no entries — but not an error either. */
    case DECLINED = 'DECLINED';

    /** A settlement naming an authorization that was never issued. E6, "Auth-Z". */
    case REJECTED_ORPHAN_SETTLEMENT = 'REJECTED_ORPHAN_SETTLEMENT';

    /** An event id already seen in this replay. Nothing is posted a second time. */
    case REJECTED_DUPLICATE_EVENT_ID = 'REJECTED_DUPLICATE_EVENT_ID';

    /** Malformed, or aimed at an account the ledger does not hold. */
    case REJECTED_INVALID_EVENT = 'REJECTED_INVALID_EVENT';

    /** True when nothing was posted and nothing reserved. */
    public function isRejection(): bool
    {
        return str_starts_with($this->value, 'REJECTED_');
    }

    /**
     * A decline is not an error. The available-balance rule working exactly as specified is
     * the ordinary path for Auth-B, and reporting it as a failure would misread the brief.
     */
    public function isError(): bool
    {
        return $this->isRejection();
    }

    public function changedTheLedger(): bool
    {
        return $this === self::POSTED;
    }
}
