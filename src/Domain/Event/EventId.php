<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Event\Exception\InvalidEventId;

/**
 * The identity of an event in the stream — "E1" through "E10".
 *
 * It is also the idempotency key: the duplicate guard is keyed on this and nothing else, so
 * two events sharing an id are the same event however different their payloads. That
 * limitation is deliberate and stated in the README.
 */
final readonly class EventId implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw InvalidEventId::blank();
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
