<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\InvalidAuthorizationId;

/**
 * The identity of an authorization — "Auth-A", "Auth-B", "Auth-Z".
 *
 * Typed rather than a bare string because it is the key a settlement is matched against, and
 * a settlement that matches nothing is a rejection the brief cares about: E6 settles "Auth-Z",
 * which was never authorized.
 */
final readonly class AuthorizationId implements \Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw InvalidAuthorizationId::blank();
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
