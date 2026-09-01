<?php

declare(strict_types=1);

namespace Ledger\Domain\Rule;

use Ledger\Domain\Event\AuthorizationEvent;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\EventOutcome;
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
     * The event-shaped entry point, returning the record the DecisionLog keeps.
     *
     * **The only method here that changes anything.** assess() below computes the verdict and
     * touches nothing; this is what places the hold, and it cannot do so without also producing
     * the Decision that records it. "No funds reserved without a log entry" is therefore a
     * property of the class rather than a convention a caller has to keep.
     *
     * A decline is not an error — the available-balance rule working exactly as specified is
     * the ordinary path for Auth-B — but it is emphatically not nothing, and an unrecorded
     * decline is indistinguishable from an event the engine never saw.
     *
     * The hold is placed on the day the authorization arrives. AuthorizationEvent carries a
     * value_date because the brief states one, but a hold is not an entry and has no value
     * date to carry; for E3 and E8 the two days coincide, so no figure depends on it.
     */
    public function apply(AuthorizationEvent $event): Decision
    {
        $verdict = $this->assess(
            $event->authorization,
            $event->account,
            $event->amount,
            $event->day,
        );

        if ($verdict->approved) {
            $this->holds->place(
                Hold::place($event->authorization, $event->account, $event->amount, $event->day),
            );
        }

        return Decision::about(
            $event,
            $verdict->approved ? EventOutcome::APPROVED : EventOutcome::DECLINED,
            $verdict->reason(),
        );
    }

    /**
     * Would this authorization be approved, and against what balance?
     *
     * Pure: it places no hold and writes nothing, which is what makes it safe to expose. Ask it
     * as often as you like and the account is exactly as it was. Reserving funds is apply()'s
     * job alone, so there is no way to change state here and skip the DecisionLog.
     *
     * @throws InvalidHold             the amount reserves nothing
     * @throws DuplicateAuthorization  the id is already holding funds
     */
    public function assess(
        AuthorizationId $authorization,
        AccountId $account,
        Money $amount,
        LedgerDay $day,
    ): AuthorizationVerdict {
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

        return $after->isNegative()
            ? AuthorizationVerdict::declined($authorization, $amount, $before, $after)
            : AuthorizationVerdict::approved($authorization, $amount, $before, $after);
    }
}
