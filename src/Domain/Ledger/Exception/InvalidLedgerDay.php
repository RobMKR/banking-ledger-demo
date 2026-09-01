<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

final class InvalidLedgerDay extends LedgerException
{
    public static function notPositive(int $number): self
    {
        return new self(sprintf('Ledger days are counted from 1; got %d.', $number));
    }

    public static function emptyRange(int $first, int $last): self
    {
        return new self(sprintf('Day range %d..%d is empty; the last day precedes the first.', $first, $last));
    }
}
