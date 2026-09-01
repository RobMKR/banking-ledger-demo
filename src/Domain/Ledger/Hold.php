<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\HoldAlreadyReleased;
use Ledger\Domain\Ledger\Exception\InvalidHold;
use Ledger\Domain\Money\Money;

/**
 * Funds reserved against an account by an approved authorization.
 *
 * A hold is not a ledger entry and never becomes one. It reduces *available* balance while it
 * is live and leaves the ledger balance untouched — which is exactly what acceptance
 * criterion 5 asserts, and why holds live outside the Ledger rather than inside it.
 *
 * The object is immutable like everything else here: releasing a hold does not mutate it, it
 * returns a released copy carrying both dates. One instance therefore records the whole
 * lifecycle — placed on Day 2, released on Day 4 — so nothing is lost when the registry swaps
 * the old instance for the new.
 */
final readonly class Hold
{
    private function __construct(
        public AuthorizationId $authorization,
        public AccountId $account,
        public Money $amount,
        public LedgerDay $placedOn,
        public ?LedgerDay $releasedOn,
    ) {
    }

    /** @throws InvalidHold the amount does not reserve anything */
    public static function place(
        AuthorizationId $authorization,
        AccountId $account,
        Money $amount,
        LedgerDay $placedOn,
    ): self {
        if (!$amount->isPositive()) {
            throw InvalidHold::notPositive($amount);
        }

        return new self($authorization, $account, $amount, $placedOn, null);
    }

    /**
     * The same hold, released.
     *
     * A settlement closes the whole authorization even when it settles for less than was
     * held: Auth-A reserves 200.00 and settles for 185.00, and the remaining 15.00 is
     * returned rather than kept reserved. That is standard card behaviour — one settlement
     * closes one authorization — and the brief's stream never settles an authorization twice,
     * so no figure in the window depends on it.
     *
     * @throws HoldAlreadyReleased
     * @throws InvalidHold released before it existed
     */
    public function released(LedgerDay $on): self
    {
        if ($this->releasedOn !== null) {
            throw HoldAlreadyReleased::on($this->authorization, $this->releasedOn);
        }

        if ($on->isBefore($this->placedOn)) {
            throw InvalidHold::releasedBeforePlaced($on, $this->placedOn);
        }

        return new self($this->authorization, $this->account, $this->amount, $this->placedOn, $on);
    }

    /** Live *now*: still reserving funds. The question the replay asks as it goes. */
    public function isActive(): bool
    {
        return $this->releasedOn === null;
    }

    /**
     * Live *on a given day* — placed by then, and not released before it.
     *
     * A different question from isActive(), and the distinction is not decoration: a hold
     * placed on Day 5 reserves nothing on Day 2, and asking "what was available on Day 2"
     * while subtracting it would collapse the two time dimensions this ledger exists to keep
     * apart. That is precisely the mistake balanceAsOf() refuses to let a caller make, and
     * available balance was making it on the hold side until this existed.
     *
     * Day granularity means a hold placed and released on the same day counts as inactive for
     * that whole day. No query in this replay straddles that case — Auth-A is placed on Day 2
     * and released on Day 4 — but it is a real limit of modelling time in whole days rather
     * than an oversight.
     */
    public function isActiveOn(LedgerDay $day): bool
    {
        return $this->placedOn->isOnOrBefore($day)
            && ($this->releasedOn === null || $this->releasedOn->isAfter($day));
    }

    public function belongsTo(AccountId $account): bool
    {
        return $this->account->equals($account);
    }
}
