<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Allocator;
use Ledger\Domain\Money\Money;

/**
 * Money in. E1, E4 and E10.
 *
 * E10 is "BHD 10.000, posted as three equal instalments", and three equal instalments of
 * 10.000 do not exist at three decimal places — which is what refutes criterion 7. The
 * allocation is 3.334 / 3.333 / 3.333 by largest remainder, and it sums to exactly 10.000.
 * Criterion 7's 3.334 x 3 = 10.002 would overpay by 0.002 and break the credit.
 *
 * A plain credit is the same path with one instalment, so nothing forks: Allocator handles
 * n = 1 by returning the amount whole.
 *
 * A split credit posts one entry per instalment, referenced "E10.1", "E10.2", "E10.3". They
 * share a value date, so no balance, fee or accrual depends on which part carries the extra
 * 0.001 (AMBIGUITIES.md §9) — but posting three entries rather than one keeps the ledger
 * honest about what the instruction actually asked for.
 */
final readonly class CreditRule
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function apply(CreditEvent $event): Decision
    {
        $instalments = Allocator::intoEqualParts($event->amount, $event->instalments);

        foreach ($instalments as $i => $part) {
            $this->ledger->append(LedgerEntry::credit(
                $event->account,
                $part,
                $event->valueDate,
                $event->day,
                $event->isSplit() ? sprintf('%s.%d', $event->id->value, $i + 1) : $event->id->value,
            ));
        }

        return Decision::about($event, EventOutcome::POSTED, $this->explain($event, $instalments));
    }

    /** @param list<Money> $instalments */
    private function explain(CreditEvent $event, array $instalments): string
    {
        if (!$event->isSplit()) {
            return sprintf(
                'Credited %s at value_date %s.',
                $event->amount->format(),
                (string) $event->valueDate,
            );
        }

        return sprintf(
            'Credited %s at value_date %s in %d instalments: %s.',
            $event->amount->format(),
            (string) $event->valueDate,
            $event->instalments,
            implode(', ', array_map(static fn (Money $m): string => $m->format(), $instalments)),
        );
    }
}
