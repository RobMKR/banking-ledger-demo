<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

final class InvalidRate extends MoneyException
{
    public static function negative(int $basisPoints): self
    {
        return new self(sprintf(
            'Rate cannot be negative; got %d basis points. A negative interest rate would '
            . 'need to be modelled as a charge, not as an accrual.',
            $basisPoints,
        ));
    }
}
