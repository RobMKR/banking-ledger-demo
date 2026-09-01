<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\AuthorizationId;

/**
 * No hold was ever placed under this id — the case E6 raises with "Auth-Z".
 *
 * The registry throws; whether an orphan settlement is a rejection or a force-post is a
 * policy question answered one layer up. See AMBIGUITIES.md §2.
 */
final class UnknownAuthorization extends LedgerException
{
    public static function named(AuthorizationId $id): self
    {
        return new self(sprintf(
            'No authorization "%s" is known to this account. Nothing was ever held under it.',
            $id->value,
        ));
    }
}
