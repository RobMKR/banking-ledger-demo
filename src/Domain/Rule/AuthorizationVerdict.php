<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Money\Money;

/**
 * The outcome of one authorization, with the arithmetic that produced it.
 *
 * A decline records the available balance it was refused against — the brief asks the report
 * to print authorization states *and* errors, and "declined" without a figure is not a reason.
 * Auth-B is declined at -155.00, and that number is the whole of its explanation.
 *
 * A *verdict*, not a Decision, and the distinction is worth holding on to. This is the rich
 * result one rule computes and then discards: typed money, both sides of the balance test.
 * `Event\Decision` is the flat record the DecisionLog keeps for every event of every type,
 * where the same arithmetic survives only as a sentence. `AuthorizationRule::apply()` is
 * where one becomes the other.
 *
 * It carries no Hold. A verdict is the answer to "does this pass the available-balance
 * test", which is a question, not an act — the hold is a consequence that only apply()
 * brings about. Holding one here would make it possible to hand back a reservation that was
 * never registered, or to read a registered one off an object that never placed it.
 */
final readonly class AuthorizationVerdict
{
    private function __construct(
        public AuthorizationId $authorization,
        public bool $approved,
        public Money $amount,
        public Money $availableBefore,
        public Money $availableAfter,
    ) {
    }

    public static function approved(
        AuthorizationId $authorization,
        Money $amount,
        Money $availableBefore,
        Money $availableAfter,
    ): self {
        return new self($authorization, true, $amount, $availableBefore, $availableAfter);
    }

    public static function declined(
        AuthorizationId $authorization,
        Money $amount,
        Money $availableBefore,
        Money $availableAfter,
    ): self {
        return new self($authorization, false, $amount, $availableBefore, $availableAfter);
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
