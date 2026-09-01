<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

final class DivisionByZero extends MoneyException
{
    public static function inRounding(): self
    {
        return new self('Cannot round a division by zero.');
    }
}
