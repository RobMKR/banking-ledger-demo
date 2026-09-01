<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

/**
 * The five kinds of event the stream contains.
 *
 * Shorter than EntryType, which has six, and the difference is the point: OVERDRAFT_FEE and
 * INTEREST are entries the ledger raises for itself at daily close. Nothing outside can ask
 * for one, so there is no event for them.
 */
enum EventType: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
    case AUTHORIZATION = 'AUTHORIZATION';
    case SETTLEMENT = 'SETTLEMENT';
    case REVERSAL = 'REVERSAL';

    /** True when the event moves money by itself. An authorization does not; it reserves. */
    public function movesMoney(): bool
    {
        return $this !== self::AUTHORIZATION;
    }
}
