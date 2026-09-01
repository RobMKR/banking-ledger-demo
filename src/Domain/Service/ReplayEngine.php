<?php

declare(strict_types=1);

namespace Ledger\Domain\Service;

use Ledger\Domain\Event\Decision;
use Ledger\Domain\Event\DecisionLog;
use Ledger\Domain\Event\EventOutcome;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Event\Exception\EventException;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Ledger\Exception\LedgerException;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Exception\MoneyException;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\DuplicateEventRule;
use Ledger\Domain\Rule\InterestAccrualRule;
use Ledger\Domain\Rule\RuleSet;

/**
 * Replays the stream, day by day, and reports what happened.
 *
 * The shape of a day is fixed and the order inside it is load-bearing:
 *
 *   1. every event booked on that day, in stream order
 *   2. the daily close — overdraft fees, on that day's *closing* balances
 *
 * Events before fees is what lets E7's backdated debit land on Day 5 and be assessed the same
 * evening, and what keeps Auth-B's decline decided against -155.00 rather than against a figure
 * three fees have already moved.
 *
 * Interest is not part of a daily close. It capitalizes once, after the final day, from a
 * schedule computed against the finished ledger — see InterestSchedule.
 *
 * Everything it needs is injected. It builds no rules of its own, so the constructor is an
 * honest list of what a replay depends on and any one of them can be swapped in a test.
 * The wiring lives in LedgerKernel, which is the only place in the codebase that knows how
 * the whole graph fits together.
 *
 * **Every event produces exactly one DecisionLog entry.** The rules return a Decision on every
 * path, and any domain exception escaping one is caught here and recorded as a rejection rather
 * than killing the replay. That is the difference between an engine that logs everything and
 * one that logs everything it happens to expect: a malformed event is a thing to report, not a
 * crash.
 */
final class ReplayEngine
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DecisionLog $log,
        private readonly DuplicateEventRule $duplicates,
        private readonly RuleSet $rules,
        private readonly DailyClose $dailyClose,
        private readonly InterestAccrualRule $interest,
    ) {
    }

    public function replay(EventStream $stream): ReplayReport
    {
        $days = $stream->days();
        $finalDay = $days === [] ? LedgerDay::of(1) : $days[count($days) - 1];

        /** @var array<int, list<LedgerEntry>> $feesByDay */
        $feesByDay = [];

        foreach (LedgerDay::through(1, $finalDay->number) as $day) {
            foreach ($this->eventsOn($stream, $day) as $event) {
                $this->log->record($this->handle($event));
            }

            $feesByDay[$day->number] = $this->dailyClose->close($day);
        }

        $this->capitalizeInterest($finalDay);

        return $this->report($finalDay, $feesByDay);
    }

    /**
     * The duplicate gate runs first — before validation, before any rule, before the ledger or
     * the hold registry are touched. A repeat must be refused on its id alone, not because some
     * later check happens to trip over it.
     */
    private function handle(LedgerEvent $event): Decision
    {
        if ($refusal = $this->duplicates->admit($event)) {
            return $refusal;
        }

        if (!$this->ledger->holds($event->account)) {
            return Decision::about($event, EventOutcome::REJECTED_INVALID_EVENT, sprintf(
                'This ledger holds no account "%s". Nothing is posted.',
                $event->account->value,
            ));
        }

        try {
            return $this->rules->applyTo($event);
        } catch (LedgerException|MoneyException|EventException $e) {
            // A malformed event is something to report, not something to die on. Without this
            // the engine would log every event it expected and none of the ones that mattered.
            return Decision::about($event, EventOutcome::REJECTED_INVALID_EVENT, $e->getMessage());
        }
    }

    /**
     * The events booked on one day, in the order the brief lists them.
     *
     * Note that filtering by day *is* the resolution of AMBIGUITIES.md §10 — a filter preserves
     * relative order, so E10 lands among the Day 5 events whether the stream is sorted first or
     * not, and swapping inReplayOrder() for asListed() here changes nothing. The sort is kept
     * because it states the intent at the point a reader looks for it, not because this loop
     * depends on it; EventStream carries its own tests for the ordering property.
     *
     * @return list<LedgerEvent>
     */
    private function eventsOn(EventStream $stream, LedgerDay $day): array
    {
        return array_values(array_filter(
            $stream->inReplayOrder(),
            static fn (LedgerEvent $e): bool => $e->day->equals($day),
        ));
    }

    private function capitalizeInterest(LedgerDay $finalDay): void
    {
        foreach ($this->ledger->accounts() as $account) {
            $this->interest->capitalize($account->id, $finalDay);
        }
    }

    /**
     * The interest actually written to the ledger — read back, never recomputed
     */
    private function postedInterestFor(Account $account): Money
    {
        $total = Money::zero($account->currency);

        foreach ($this->ledger->entriesOfType($account->id, EntryType::INTEREST) as $entry) {
            $total = $total->plus($entry->amount);
        }

        return $total;
    }

    /** @param array<int, list<LedgerEntry>> $feesByDay */
    private function report(LedgerDay $finalDay, array $feesByDay): ReplayReport
    {
        $lines = [];
        $closing = [];
        $interest = [];

        foreach ($this->ledger->accounts() as $account) {
            foreach (LedgerDay::through(1, $finalDay->number) as $day) {
                $lines[] = new DailyLine(
                    $day,
                    $account->id,
                    $this->ledger->balanceAsOf($account->id, $day, $day),
                    $this->ledger->balanceAsOf($account->id, $day, $finalDay),
                    array_values(array_filter(
                        $feesByDay[$day->number] ?? [],
                        static fn ($fee): bool => $fee->belongsTo($account->id),
                    )),
                    array_values(array_filter(
                        $this->log->onDay($day),
                        static fn (Decision $d): bool => $d->account->equals($account->id),
                    )),
                    array_values(array_filter(
                        $this->ledger->entriesFor($account->id),
                        static fn (LedgerEntry $e): bool => $e->bookedDay->equals($day),
                    )),
                );
            }

            $closing[$account->id->value] = $this->ledger->balanceAsOf($account->id, $finalDay, $finalDay);
            $interest[$account->id->value] = $this->postedInterestFor($account);
        }

        return new ReplayReport($finalDay, $lines, $closing, $interest);
    }
}
