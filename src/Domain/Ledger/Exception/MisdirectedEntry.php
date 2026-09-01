<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Money\Money;

final class MisdirectedEntry extends LedgerException
{
    public static function wrongSign(EntryType $type, Money $amount): self
    {
        return new self(sprintf(
            'A %s entry cannot hold %s; its sign contradicts the direction of the entry.',
            $type->value,
            (string) $amount,
        ));
    }
}
