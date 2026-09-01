<?php

declare(strict_types=1);

namespace Ledger\Domain\Event;

use Ledger\Domain\Ledger\LedgerDay;

/**
 * The events to replay, and the order to replay them in.
 *
 * The brief lists its stream "replayed in this order", and then lists E10 — a Day 5 event —
 * after E9, which is Day 6. Taking the listing literally would replay a Day 5 credit after
 * Day 6 had closed. See AMBIGUITIES.md §10.
 *
 * Resolved by sorting on the presentation day and preserving the given order within a day.
 * Both halves matter: the sort puts E10 back among the Day 5 events, and the stability keeps
 * E7 ahead of E8 on Day 5, which decides whether Auth-B is judged against -155.00 or +465.00.
 * PHP's sort has been stable since 8.0, so the given order survives ties by itself.
 *
 * It changes nothing in this particular stream — E10 only touches ACC-002, which no other
 * event goes near — but leaving replay order to the accident of how a list was typed is a
 * latent bug, not a saved decision.
 */
final readonly class EventStream implements \IteratorAggregate, \Countable
{
    /** @var list<LedgerEvent> */
    private array $events;

    public function __construct(LedgerEvent ...$events)
    {
        $this->events = array_values($events);
    }

    /** As given, unsorted — what the brief literally lists. @return list<LedgerEvent> */
    public function asListed(): array
    {
        return $this->events;
    }

    /** @return list<LedgerEvent> */
    public function inReplayOrder(): array
    {
        $ordered = $this->events;
        usort($ordered, static fn (LedgerEvent $a, LedgerEvent $b): int => $a->day->compareTo($b->day));

        return $ordered;
    }

    /** Every day the stream touches, ascending. @return list<LedgerDay> */
    public function days(): array
    {
        $seen = [];
        foreach ($this->events as $event) {
            $seen[$event->day->number] = $event->day;
        }
        ksort($seen);

        return array_values($seen);
    }

    /** @return \Traversable<int, LedgerEvent> */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->inReplayOrder());
    }

    public function count(): int
    {
        return count($this->events);
    }
}
