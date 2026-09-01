<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Event\Exception\InvalidEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

/**
 * A request to reserve funds. E3 (Auth-A, approved) and E8 (Auth-B, declined).
 *
 * The one event type that never writes to the ledger whatever the outcome — approved it
 * places a hold, declined it does nothing. That is criterion 5 restated.
 *
 * The brief gives this event a value_date and nothing can use it: a hold is not an entry, so
 * it has no value date to carry. It is kept on the object because the stream states it, and
 * dropping data the spec supplies is how a reader loses the ability to check the work. The
 * hold itself is placed on the day the authorization arrives; for E3 and E8 the two coincide,
 * so no figure depends on the distinction.
 */
final readonly class AuthorizationEvent extends LedgerEvent
{
    public function __construct(
        EventId $id,
        AccountId $account,
        public AuthorizationId $authorization,
        public Money $amount,
        LedgerDay $day,
        LedgerDay $valueDate,
    ) {
        if (!$amount->isPositive()) {
            throw InvalidEvent::amountNotPositive($id, $amount);
        }

        parent::__construct($id, $account, $day, $valueDate);
    }

    public function type(): EventType
    {
        return EventType::AUTHORIZATION;
    }

    public function describe(): string
    {
        return sprintf('AUTHORIZATION %s holding %s', $this->authorization->value, (string) $this->amount);
    }
}
