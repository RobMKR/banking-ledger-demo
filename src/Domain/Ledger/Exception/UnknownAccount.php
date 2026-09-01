<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\AccountId;

final class UnknownAccount extends LedgerException
{
    public static function named(AccountId $id): self
    {
        return new self(sprintf(
            'Account "%s" is not held by this ledger. Accounts are declared when the ledger '
            . 'is opened; entries can never create one implicitly.',
            $id->value,
        ));
    }
}
