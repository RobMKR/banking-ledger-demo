<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

final class InvalidAccountId extends LedgerException
{
    public static function blank(): self
    {
        return new self('An account id cannot be blank.');
    }
}
