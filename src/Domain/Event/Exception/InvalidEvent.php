<?php

declare(strict_types=1);

namespace Ledger\Domain\Event\Exception;

use Ledger\Domain\Event\EventId;
use Ledger\Domain\Money\Money;

final class InvalidEvent extends EventException
{
    /**
     * Direction lives in the event type, not in the sign of its amount. "CREDIT -100.00" is
     * not a debit expressed differently; it is a malformed event, and accepting it would let
     * the same figure mean two opposite things depending on which field you read.
     */
    public static function amountNotPositive(EventId $id, Money $amount): self
    {
        return new self(sprintf(
            'Event %s carries %s. An event states its direction in its type, so its amount '
            . 'must be positive.',
            $id->value,
            (string) $amount,
        ));
    }

    public static function instalmentsNotPositive(EventId $id, int $instalments): self
    {
        return new self(sprintf(
            'Event %s asks to be posted in %d instalments. A credit posts at least once.',
            $id->value,
            $instalments,
        ));
    }
}
