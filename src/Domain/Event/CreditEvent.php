<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Event\Exception\InvalidEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

/**
 * Money in. E1, E4 and E10.
 *
 * E10 is "BHD 10.000, posted as three equal instalments", which is a credit with a split
 * count rather than a different kind of event — so instalments is a field, defaulting to one,
 * and a plain credit is the same code path with n = 1. The alternative, a separate
 * InstalmentCredit type, would fork the posting logic to express what is really one number.
 *
 * Three equal instalments of 10.000 do not exist at three decimal places; the allocation is
 * 3.334 / 3.333 / 3.333, which is what refutes criterion 7. See AMBIGUITIES.md §9.
 */
final readonly class CreditEvent extends LedgerEvent
{
    public function __construct(
        EventId $id,
        AccountId $account,
        public Money $amount,
        LedgerDay $day,
        LedgerDay $valueDate,
        public int $instalments = 1,
    ) {
        if (!$amount->isPositive()) {
            throw InvalidEvent::amountNotPositive($id, $amount);
        }

        if ($instalments < 1) {
            throw InvalidEvent::instalmentsNotPositive($id, $instalments);
        }

        parent::__construct($id, $account, $day, $valueDate);
    }

    public function type(): EventType
    {
        return EventType::CREDIT;
    }

    public function isSplit(): bool
    {
        return $this->instalments > 1;
    }

    public function describe(): string
    {
        return $this->isSplit()
            ? sprintf('CREDIT %s in %d instalments', (string) $this->amount, $this->instalments)
            : sprintf('CREDIT %s', (string) $this->amount);
    }
}
