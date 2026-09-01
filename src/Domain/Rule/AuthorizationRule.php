<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\AvailableBalance;
use Ledger\Domain\Ledger\Exception\DuplicateAuthorization;
use Ledger\Domain\Ledger\Exception\InvalidHold;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

/**
 * The brief's third non-negotiable rule, and the only one it states as a test rather than a
 * calculation:
 *
 *   "An authorization is approved only if the account's available balance — ledger balance
 *    minus active holds — remains at or above zero after the hold is applied."
 *
 * "At or above" is inclusive, so an authorization that lands exactly on zero is approved. The
 * rule places the hold itself on approval rather than handing one back to be placed, so
 * "approved" and "funds reserved" cannot come apart at a call site.
 *
 * Nothing here touches the Ledger. An authorization moves no money; it is the settlement that
 * does, days later or never. That is criterion 5 restated as code.
 */
final readonly class AuthorizationRule
{
    private AvailableBalance $available;

    public function __construct(
        private Ledger $ledger,
        private HoldRegistry $holds,
    ) {
        $this->available = new AvailableBalance($ledger, $holds);
    }

    /**
     * @throws InvalidHold             the amount reserves nothing
     * @throws DuplicateAuthorization  the id is already holding funds
     */
    public function authorize(
        AuthorizationId $authorization,
        AccountId $account,
        Money $amount,
        LedgerDay $day,
    ): AuthorizationDecision {
        $held = $this->ledger->account($account);
        $held->assertHolds($amount);

        // Checked before the balance test, not inside Hold::place afterwards: a negative
        // amount would otherwise sail through as an approval that *raises* available balance.
        if (!$amount->isPositive()) {
            throw InvalidHold::notPositive($amount);
        }

        if ($this->holds->has($authorization)) {
            throw DuplicateAuthorization::named($authorization);
        }

        $before = $this->available->on($account, $day);
        $after = $before->minus($amount);

        if ($after->isNegative()) {
            return AuthorizationDecision::declined($authorization, $amount, $before, $after);
        }

        $hold = Hold::place($authorization, $account, $amount, $day);
        $this->holds->place($hold);

        return AuthorizationDecision::approved($hold, $before, $after);
    }
}
