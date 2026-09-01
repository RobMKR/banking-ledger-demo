<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\InvalidAccountId;

final readonly class AccountId implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw InvalidAccountId::blank();
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
