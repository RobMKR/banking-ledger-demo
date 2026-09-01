<?php

declare(strict_types=1);

namespace Ledger\Application;

use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Event\ProcessedEvents;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AvailableBalance;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Money\Rate;
use Ledger\Domain\Rule\AuthorizationRule;
use Ledger\Domain\Rule\CreditRule;
use Ledger\Domain\Rule\DebitRule;
use Ledger\Domain\Rule\DuplicateEventRule;
use Ledger\Domain\Rule\InterestAccrualRule;
use Ledger\Domain\Rule\OverdraftFeeRule;
use Ledger\Domain\Rule\ReversalRule;
use Ledger\Domain\Rule\RuleSet;
use Ledger\Domain\Rule\SettlementRule;
use Ledger\Domain\Service\DailyClose;
use Ledger\Domain\Service\InterestSchedule;
use Ledger\Domain\Service\ReplayEngine;
use Ledger\Domain\Service\ReplayReport;

/**
 * The composition root: the one place that knows how the whole graph fits together.
 *
 * Everything below it takes its collaborators through its constructor and builds nothing. That
 * is the point — `ReplayEngine` used to construct nine rules inside itself, which meant its
 * constructor lied about what a replay depends on and no test could substitute a single piece
 * of it. Now the dependencies are declared and the wiring is here, once.
 *
 * **Hand-written, with no container library**, and deliberately so. A container earns its place
 * when wiring is large, conditional, or configured at runtime; this graph is fifteen objects
 * with one arrangement, and reflection-driven autowiring would replace a list you can read with
 * a magic you cannot. The same reasoning that dropped symfony/console: the dependency would buy
 * less than it costs. See REJECTED.md.
 *
 * The brief's two non-negotiable constants live here — AED 25.00 and 0.04% per day. Accounts
 * arrive as an argument rather than being hardcoded, because the accounts are scenario *data*
 * and belong to the event source; a use case reaching into Infrastructure for them would point
 * the dependency the wrong way.
 */
final readonly class LedgerKernel
{
    private function __construct(
        public Ledger $ledger,
        public HoldRegistry $holds,
        public DecisionLog $log,
        public ProcessedEvents $processed,
        public ReplayEngine $engine,
    ) {
    }

    /** The brief's configuration: an AED 25.00 overdraft fee and 0.04% daily interest. */
    public static function build(Account ...$accounts): self
    {
        return self::with(
            $accounts,
            Money::of('25.00', Currency::AED),
            Rate::fromBasisPoints(4),
        );
    }

    /**
     * The same graph with the two constants set explicitly.
     *
     * Not a policy switch — the four resolved ambiguities remain hardcoded and unreachable from
     * here, which is the whole reason there are no flags. This exists so NUMBERS.md's
     * sensitivity claims ("why that value and not half it") can be exercised by a test rather
     * than asserted in prose.
     *
     * @param list<Account> $accounts
     */
    public static function with(array $accounts, Money $overdraftFee, Rate $interestRate): self
    {
        $ledger = new Ledger(...$accounts);
        $holds = new HoldRegistry();
        $log = new DecisionLog();
        $processed = new ProcessedEvents();

        $schedule = new InterestSchedule($ledger, $interestRate);

        $engine = new ReplayEngine(
            $ledger,
            $log,
            new DuplicateEventRule($processed),
            new RuleSet(
                new CreditRule($ledger),
                new DebitRule($ledger),
                new AuthorizationRule($ledger, $holds, new AvailableBalance($ledger, $holds)),
                new SettlementRule($ledger, $holds),
                new ReversalRule($ledger),
            ),
            new DailyClose($ledger, new OverdraftFeeRule($ledger, $overdraftFee), $overdraftFee),
            $schedule,
            new InterestAccrualRule($ledger, $schedule),
        );

        return new self($ledger, $holds, $log, $processed, $engine);
    }

    public function replay(EventStream $stream): ReplayReport
    {
        return $this->engine->replay($stream);
    }
}
