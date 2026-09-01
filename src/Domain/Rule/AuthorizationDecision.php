<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Money\Money;

/**
 * The outcome of one authorization, with the arithmetic that produced it.
 *
 * A decline records the available balance it was refused against — the brief asks the report
 * to print authorization states *and* errors, and "declined" without a figure is not a reason.
 * Auth-B is declined at -155.00, and that number is the whole of its explanation.
 */
final readonly class AuthorizationDecision
{
    private function __construct(
        public AuthorizationId $authorization,
        public bool $approved,
        public Money $amount,
        public Money $availableBefore,
        public Money $availableAfter,
        public ?Hold $hold,
    ) {
    }

    public static function approved(Hold $hold, Money $availableBefore, Money $availableAfter): self
    {
        return new self(
            $hold->authorization,
            true,
            $hold->amount,
            $availableBefore,
            $availableAfter,
            $hold,
        );
    }

    public static function declined(
        AuthorizationId $authorization,
        Money $amount,
        Money $availableBefore,
        Money $availableAfter,
    ): self {
        return new self($authorization, false, $amount, $availableBefore, $availableAfter, null);
    }

    public function isDeclined(): bool
    {
        return !$this->approved;
    }

    /** APPROVED / DECLINED — the authorization state the per-day report prints. */
    public function state(): string
    {
        return $this->approved ? 'APPROVED' : 'DECLINED';
    }

    public function reason(): string
    {
        return sprintf(
            '%s: available %s less a hold of %s leaves %s, %s zero.',
            $this->state(),
            $this->availableBefore->format(),
            $this->amount->format(),
            $this->availableAfter->format(),
            $this->approved ? 'at or above' : 'below',
        );
    }
}
