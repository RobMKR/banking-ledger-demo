<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\DebitEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerEntry;

/**
 * Money out. E2 and E7.
 *
 * **A debit posts whatever it does to the balance.** There is no available-balance check here,
 * and that is not an omission: the brief makes that test non-negotiable for *authorizations*
 * only. It is also what makes the whole exercise possible — E7's 620.00 drives Day 2 to
 * -370.00 with nothing refusing it, and the overdraft cascade follows from that. A real ledger
 * behaves the same way; a settled debit has already happened. See AMBIGUITIES.md §11.
 *
 * E7 carries value_date Day 2 while arriving on Day 5, so this is where a backdated entry
 * enters the ledger and reopens three already-closed days for assessment.
 */
final readonly class DebitRule
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function apply(DebitEvent $event): Decision
    {
        $this->ledger->append(LedgerEntry::debit(
            $event->account,
            $event->amount,
            $event->valueDate,
            $event->day,
            $event->id->value,
        ));

        return Decision::about($event, EventOutcome::POSTED, sprintf(
            'Debited %s at value_date %s%s.',
            $event->amount->format(),
            (string) $event->valueDate,
            $event->isBackdated() ? sprintf(', backdated from %s', (string) $event->day) : '',
        ));
    }
}
