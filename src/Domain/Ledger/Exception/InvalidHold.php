<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

final class InvalidHold extends LedgerException
{
    /**
     * A hold reserves funds, so its amount is positive by definition. The guard matters more
     * than it looks: a negative hold would *raise* available balance, turning the
     * authorization check into a way of manufacturing headroom.
     */
    public static function notPositive(Money $amount): self
    {
        return new self(sprintf(
            'A hold must reserve a positive amount; %s reserves nothing. A negative hold '
            . 'would increase available balance rather than reduce it.',
            (string) $amount,
        ));
    }

    public static function releasedBeforePlaced(LedgerDay $released, LedgerDay $placed): self
    {
        return new self(sprintf(
            'A hold placed on %s cannot be released on %s.',
            (string) $placed,
            (string) $released,
        ));
    }
}
