<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Event\Exception\InvalidEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

/**
 * An authorization coming good. E5 (Auth-A, 185.00) and E6 (Auth-Z, orphaned).
 *
 * The settled amount need not equal what was held: Auth-A reserves 200.00 and settles 185.00.
 * E6 names an authorization that was never issued, and criterion 4 says to reject it and keep
 * the funds — which this build honours over card-network practice, where a settlement without
 * a preceding authorization is a force-post and the money has already moved.
 * See AMBIGUITIES.md §2.
 */
final readonly class SettlementEvent extends LedgerEvent
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
        return EventType::SETTLEMENT;
    }

    public function describe(): string
    {
        return sprintf('SETTLEMENT %s for %s', $this->authorization->value, (string) $this->amount);
    }
}
