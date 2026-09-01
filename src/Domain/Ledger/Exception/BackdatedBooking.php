<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger\Exception;

use Ledger\Domain\Ledger\LedgerDay;

/**
 * A backdated *value date* is legitimate — it is the whole point of the exercise, and E7 does
 * exactly that. A backdated *booking* is not: it would mean rewriting what the ledger already
 * knew on a day that has passed, which append-only forbids.
 */
final class BackdatedBooking extends LedgerException
{
    public static function before(LedgerDay $attempted, LedgerDay $highest): self
    {
        return new self(sprintf(
            'Cannot book on %s when the ledger has already booked on %s. A backdated value '
            . 'date is allowed; a backdated booking would rewrite what was already known.',
            (string) $attempted,
            (string) $highest,
        ));
    }
}
