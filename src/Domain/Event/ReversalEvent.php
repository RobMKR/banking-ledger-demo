<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;

/**
 * The undoing of an earlier event. E9, which reverses E7.
 *
 * It carries no amount. The figure is whatever the original posted, which is the only way a
 * reversal can be guaranteed to net to zero — restating the amount would let the two disagree.
 *
 * It does not delete anything. Under append-only a reversal appends the inverse, so both
 * entries stand in the ledger for good. That is why criterion 6 fails: the balances do return
 * to their pre-E7 values, but the three fees E7 caused were booked records and remain.
 */
final readonly class ReversalEvent extends LedgerEvent
{
    public function __construct(
        EventId $id,
        AccountId $account,
        public EventId $reverses,
        LedgerDay $day,
        LedgerDay $valueDate,
    ) {
        parent::__construct($id, $account, $day, $valueDate);
    }

    public function type(): EventType
    {
        return EventType::REVERSAL;
    }

    public function describe(): string
    {
        return sprintf('REVERSAL of %s', $this->reverses->value);
    }
}
