<?php

declare(strict_types=1);

namespace Ledger\Application\Port;

use Ledger\Domain\Service\ReplayReport;

/**
 * How a finished replay is rendered.
 *
 * The one interface in this codebase with more than one implementation, which is exactly why it
 * exists: a human reading a table and a golden test parsing JSON want the same figures in
 * different shapes. Everywhere else — rules, event source, ledger — there is a single
 * implementation and an interface would be a seam with nothing to pass through it.
 */
interface ClosePresenter
{
    public function present(ReplayReport $report): string;
}
