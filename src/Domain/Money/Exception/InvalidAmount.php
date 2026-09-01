<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

use Ledger\Domain\Money\Currency;

final class InvalidAmount extends MoneyException
{
    public static function malformed(string $amount): self
    {
        return new self(sprintf(
            'Amount "%s" is not a decimal number; expected an optional "-" then digits, '
            . 'optionally followed by "." and digits.',
            $amount,
        ));
    }

    /**
     * Input carrying more decimals than the currency can represent is refused rather than
     * rounded. Rounding happens only at explicit, named boundaries — never on the way in.
     */
    public static function tooPrecise(string $amount, Currency $currency): self
    {
        return new self(sprintf(
            'Amount "%s" has more than %d decimal places and cannot be represented in %s. '
            . 'Round it explicitly before constructing Money.',
            $amount,
            $currency->exponent(),
            $currency->value,
        ));
    }
}
