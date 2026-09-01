<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Event;

use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventStream::class)]
final class EventStreamTest extends TestCase
{
    /** @return list<string> */
    private static function ids(array $events): array
    {
        return array_map(static fn (LedgerEvent $e): string => $e->id->value, $events);
    }

    private static function creditOn(string $id, int $day): CreditEvent
    {
        return new CreditEvent(
            EventId::of($id),
            AccountId::of(AssessmentStream::ACC1),
            Money::of('1.00', Currency::AED),
            LedgerDay::of($day),
            LedgerDay::of($day),
        );
    }

    // ==================================================== AMBIGUITIES.md §10

    /**
     * The brief says its stream is "replayed in this order" and then lists E10 — a Day 5
     * event — after E9, which is Day 6. Taken literally that replays a Day 5 credit after
     * Day 6 has closed.
     */
    public function testTheBriefsOwnListingIsNotInDayOrder(): void
    {
        $listed = self::ids(AssessmentStream::asListed()->asListed());

        self::assertSame(['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9', 'E10'], $listed);

        $days = array_map(
            static fn (LedgerEvent $e): int => $e->day->number,
            AssessmentStream::asListed()->asListed(),
        );
        self::assertSame([1, 1, 2, 3, 4, 4, 5, 5, 6, 5], $days, 'E10 is a Day 5 event listed last');
    }

    public function testReplayOrderPutsE10BackAmongTheDayFiveEvents(): void
    {
        self::assertSame(
            ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E10', 'E9'],
            self::ids(AssessmentStream::asListed()->inReplayOrder()),
        );
    }

    /**
     * The stability half of the resolution, and the half that has teeth.
     *
     * Within Day 5 the given order is E7 then E8, and it must survive the sort: E7 is the
     * backdated 620.00 debit and E8 is Auth-B. Reorder them and Auth-B is judged against
     * +465.00 instead of -155.00, and approves. The one decision in the stream that the
     * exercise is built around would flip on the sort algorithm's tie-breaking.
     */
    public function testOrderWithinADayIsPreserved(): void
    {
        $dayFive = array_values(array_filter(
            AssessmentStream::asListed()->inReplayOrder(),
            static fn (LedgerEvent $e): bool => $e->day->number === 5,
        ));

        self::assertSame(['E7', 'E8', 'E10'], self::ids($dayFive));
    }

    public function testASortIsStableAcrossALongerRunOfTies(): void
    {
        $stream = new EventStream(
            self::creditOn('A', 3),
            self::creditOn('B', 1),
            self::creditOn('C', 3),
            self::creditOn('D', 1),
            self::creditOn('E', 3),
            self::creditOn('F', 2),
            self::creditOn('G', 3),
        );

        self::assertSame(['B', 'D', 'F', 'A', 'C', 'E', 'G'], self::ids($stream->inReplayOrder()));
    }

    public function testAsListedIsLeftAlone(): void
    {
        $stream = AssessmentStream::asListed();
        $stream->inReplayOrder();

        self::assertSame(
            ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9', 'E10'],
            self::ids($stream->asListed()),
            'sorting must not disturb the stream as given',
        );
    }

    // ==================================================== the rest

    public function testIteratingAStreamWalksItInReplayOrder(): void
    {
        self::assertSame(
            ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E10', 'E9'],
            self::ids(iterator_to_array(AssessmentStream::asListed())),
        );
    }

    public function testTheStreamHasTenEvents(): void
    {
        self::assertCount(10, AssessmentStream::asListed());
    }

    /** The six-day window, derived from the stream rather than hardcoded beside it. */
    public function testTheDaysTouchedAreTheSixDayWindow(): void
    {
        $days = array_map(
            static fn (LedgerDay $d): int => $d->number,
            AssessmentStream::asListed()->days(),
        );

        self::assertSame([1, 2, 3, 4, 5, 6], $days);
    }

    public function testDaysAreDistinctAndAscendingEvenWhenTheStreamIsNot(): void
    {
        $stream = new EventStream(self::creditOn('A', 6), self::creditOn('B', 2), self::creditOn('C', 6));

        self::assertSame([2, 6], array_map(static fn (LedgerDay $d): int => $d->number, $stream->days()));
    }

    public function testAnEmptyStreamTouchesNoDays(): void
    {
        $stream = new EventStream();

        self::assertCount(0, $stream);
        self::assertSame([], $stream->days());
        self::assertSame([], $stream->inReplayOrder());
    }
}
