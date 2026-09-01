<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

use Ledger\Domain\Money\Currency;

final class CurrencyMismatch extends MoneyException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(sprintf(
            'Cannot combine %s with %s; money of different currencies is never commensurable.',
            $left->value,
            $right->value,
        ));
    }
}
