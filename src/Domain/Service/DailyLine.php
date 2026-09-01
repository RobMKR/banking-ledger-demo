<?php

declare(strict_types=1);

namespace Ledger\Domain\Service;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Money;

/**
 * One account's row for one day.
 *
 * It carries **two** closing balances, because in a bitemporal ledger "Day 2's closing balance"
 * is not one number. `closingAsKnownThen` is what the day closed at when it closed;
 * `closingRestated` is what it closed at once everything was known. For Day 2 those are
 * -395.00 and 225.00, and both are correct — they answer different questions. Printing only
 * the first hides E9; printing only the second hides why three fees were ever charged.
 * See AMBIGUITIES.md §6.
 *
 * @phpstan-type Fees list<LedgerEntry>
 */
final readonly class DailyLine
{
    /**
     * @param list<LedgerEntry> $fees      fees raised at this day's close
     * @param list<Decision>    $decisions every event processed on this day, in order
     * @param list<LedgerEntry> $postings  every entry booked on this day, fees included
     */
    public function __construct(
        public LedgerDay $day,
        public AccountId $account,
        public Money $closingAsKnownThen,
        public Money $closingRestated,
        public array $fees,
        public array $decisions,
        public array $postings = [],
    ) {
    }

    public function wasRestated(): bool
    {
        return !$this->closingAsKnownThen->equals($this->closingRestated);
    }

    /** @return list<Decision> the events that were refused — the "errors" column */
    public function errors(): array
    {
        return array_values(array_filter($this->decisions, static fn (Decision $d): bool => $d->isRejection()));
    }

    /** @return list<Decision> approvals and declines — the "authorization states" column */
    public function authorizations(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (Decision $d): bool => $d->outcome->name === 'APPROVED' || $d->outcome->name === 'DECLINED',
        ));
    }

    public function feeTotal(): Money
    {
        $total = Money::zero($this->closingRestated->currency);

        foreach ($this->fees as $fee) {
            $total = $total->plus($fee->amount);
        }

        return $total->negated();
    }
}
