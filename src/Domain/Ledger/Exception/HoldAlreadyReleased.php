<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\LedgerDay;

final class HoldAlreadyReleased extends LedgerException
{
    public static function on(AuthorizationId $id, LedgerDay $releasedOn): self
    {
        return new self(sprintf(
            'The hold for "%s" was already released on %s. Releasing it again would return '
            . 'the reserved funds twice.',
            $id->value,
            (string) $releasedOn,
        ));
    }
}
