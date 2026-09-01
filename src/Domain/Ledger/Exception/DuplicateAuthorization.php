<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\AuthorizationId;

/**
 * Two authorizations claiming one id. No such event is in the stream; the guard exists
 * because without it the second would silently reserve funds twice under one key, and the
 * first hold would become unreleasable.
 */
final class DuplicateAuthorization extends LedgerException
{
    public static function named(AuthorizationId $id): self
    {
        return new self(sprintf(
            'Authorization "%s" already holds funds on this account. Re-using a live '
            . 'authorization id would reserve the same funds twice.',
            $id->value,
        ));
    }
}
