<?php

declare(strict_types=1);

namespace Ledger\Domain\Money\Exception;

/**
 * PHP silently promotes an overflowing int to float, which would destroy exactness without
 * any error. Every arithmetic path in Money checks for it and raises this instead.
 */
final class ArithmeticOverflow extends MoneyException
{
    public static function inOperation(string $operation): self
    {
        return new self(sprintf(
            'Operation "%s" overflowed the 64-bit integer range; the result would have been '
            . 'silently converted to a float and lost exactness.',
            $operation,
        ));
    }

    public static function forAmount(string $amount): self
    {
        return new self(sprintf(
            'Amount "%s" is too large to represent exactly in 64-bit minor units.',
            $amount,
        ));
    }
}
