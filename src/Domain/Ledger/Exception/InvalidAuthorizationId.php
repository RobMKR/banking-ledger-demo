<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

final class InvalidAuthorizationId extends LedgerException
{
    public static function blank(): self
    {
        return new self('An authorization id cannot be blank.');
    }
}
