<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\InvalidLedgerDay;

/**
 * A day in the ledger's window, counted from 1.
 *
 * The type exists because the ledger carries *two* independent dates per entry — the day the
 * money belongs to (value date) and the day the ledger learned of it (booked day). Passing
 * bare ints invites transposing them, and transposing them is the one mistake that silently
 * produces a plausible-looking wrong answer.
 */
final readonly class LedgerDay implements \Stringable
{
    private function __construct(public int $number)
    {
    }

    public static function of(int $number): self
    {
        if ($number < 1) {
            throw InvalidLedgerDay::notPositive($number);
        }

        return new self($number);
    }

    /** @return list<self> */
    public static function through(int $first, int $last): array
    {
        if ($last < $first) {
            throw InvalidLedgerDay::emptyRange($first, $last);
        }

        return array_map(self::of(...), range($first, $last));
    }

    public function isOnOrBefore(self $other): bool
    {
        return $this->number <= $other->number;
    }

    public function isBefore(self $other): bool
    {
        return $this->number < $other->number;
    }

    public function isAfter(self $other): bool
    {
        return $this->number > $other->number;
    }

    public function equals(self $other): bool
    {
        return $this->number === $other->number;
    }

    public function compareTo(self $other): int
    {
        return $this->number <=> $other->number;
    }

    public function __toString(): string
    {
        return 'Day ' . $this->number;
    }
}
