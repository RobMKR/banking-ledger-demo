<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Event\Exception\InvalidEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

/**
 * Money out. E2 and E7.
 *
 * A debit posts whatever it does to the balance. The available-balance test the brief makes
 * non-negotiable applies to authorizations only, which is what lets E7 drive Day 2 to
 * -370.00 without anything refusing it — and is the reason the overdraft cascade exists at
 * all. See AMBIGUITIES.md §11.
 */
final readonly class DebitEvent extends LedgerEvent
{
    public function __construct(
        EventId $id,
        AccountId $account,
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
        return EventType::DEBIT;
    }

    public function describe(): string
    {
        return sprintf('DEBIT %s', (string) $this->amount);
    }
}
