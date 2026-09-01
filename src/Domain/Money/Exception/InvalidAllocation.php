<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

final class InvalidAllocation extends MoneyException
{
    public static function nonPositiveParts(int $parts): self
    {
        return new self(sprintf('Cannot split an amount into %d parts; expected at least 1.', $parts));
    }
}
