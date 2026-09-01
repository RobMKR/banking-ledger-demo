<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Money\Currency;

final class AccountCurrencyMismatch extends LedgerException
{
    public static function between(AccountId $id, Currency $account, Currency $amount): self
    {
        return new self(sprintf(
            'Account "%s" is denominated in %s and cannot hold an entry in %s.',
            $id->value,
            $account->value,
            $amount->value,
        ));
    }
}
