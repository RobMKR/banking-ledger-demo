<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;

/**
 * Every event the engine saw and what it did with each.
 *
 * The counterpart to the Ledger, and the reason the two are separate: the Ledger records
 * money, the log records decisions. E6 and E8 produce no entry at all, so without this they
 * would leave no trace, and "the funds must not leave the account" would be indistinguishable
 * from the engine having quietly dropped the event on the floor.
 *
 * Append-only for the same reason the Ledger is, and structurally so: no remove, no replace,
 * and every Decision is readonly.
 */
final class DecisionLog
{
    /** @var list<Decision> */
    private array $decisions = [];

    /** The only mutating operation on this class. */
    public function record(Decision $decision): void
    {
        $this->decisions[] = $decision;
    }

    /** @return list<Decision> in the order the events were processed */
    public function all(): array
    {
        return $this->decisions;
    }

    /** @return list<Decision> */
    public function onDay(LedgerDay $day): array
    {
        return $this->matching(static fn (Decision $d): bool => $d->day->equals($day));
    }

    /** @return list<Decision> */
    public function forAccount(AccountId $account): array
    {
        return $this->matching(static fn (Decision $d): bool => $d->account->equals($account));
    }

    /** @return list<Decision> */
    public function withOutcome(EventOutcome $outcome): array
    {
        return $this->matching(static fn (Decision $d): bool => $d->outcome === $outcome);
    }

    /** Everything the engine refused, in order. @return list<Decision> */
    public function rejections(): array
    {
        return $this->matching(static fn (Decision $d): bool => $d->isRejection());
    }

    /**
     * The decision about one event, or null if it was never seen.
     *
     * Returns the *first*, which matters once duplicate rejection exists: a repeated id
     * records a second decision, and the first is the one that posted.
     */
    public function about(EventId $event): ?Decision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->event->equals($event)) {
                return $decision;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->decisions);
    }

    /**
     * @param callable(Decision): bool $predicate
     * @return list<Decision>
     */
    private function matching(callable $predicate): array
    {
        return array_values(array_filter($this->decisions, $predicate));
    }
}
