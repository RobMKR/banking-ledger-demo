<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Event;

use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\ProcessedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessedEvents::class)]
final class ProcessedEventsTest extends TestCase
{
    private static function id(string $value): EventId
    {
        return EventId::of($value);
    }

    public function testANewRegistryHasSeenNothing(): void
    {
        $processed = new ProcessedEvents();

        self::assertFalse($processed->hasSeen(self::id('E1')));
        self::assertSame(0, $processed->count());
    }

    /**
     * remember() is one operation, not a check followed by a record. Split in two, a caller
     * can test and then forget to record — and the failure mode of that mistake is a silent
     * double-post into a ledger with no way to delete it.
     */
    public function testRememberingReportsWhetherTheSightingWasTheFirst(): void
    {
        $processed = new ProcessedEvents();

        self::assertTrue($processed->remember(self::id('E7')), 'first sighting');
        self::assertFalse($processed->remember(self::id('E7')), 'second');
        self::assertFalse($processed->remember(self::id('E7')), 'and every one after');
    }

    public function testRememberingRecordsTheId(): void
    {
        $processed = new ProcessedEvents();
        $processed->remember(self::id('E7'));

        self::assertTrue($processed->hasSeen(self::id('E7')));
        self::assertFalse($processed->hasSeen(self::id('E8')));
    }

    public function testRepeatedSightingsCountOnce(): void
    {
        $processed = new ProcessedEvents();
        $processed->remember(self::id('E1'));
        $processed->remember(self::id('E1'));
        $processed->remember(self::id('E2'));

        self::assertSame(2, $processed->count());
    }

    /** Two ids are the same only when they are equal, not when one is a prefix of the other. */
    public function testDistinctIdsDoNotCollide(): void
    {
        $processed = new ProcessedEvents();

        self::assertTrue($processed->remember(self::id('E1')));
        self::assertTrue($processed->remember(self::id('E10')), 'E10 is not a repeat of E1');
        self::assertSame(2, $processed->count());
    }

    /** Ids are compared by value. The same id arriving as a different object is still a repeat. */
    public function testIdentityIsByValueNotByObject(): void
    {
        $processed = new ProcessedEvents();
        $processed->remember(EventId::of('E7'));

        self::assertFalse($processed->remember(EventId::of('E7')), 'a freshly built id, same value');
    }
}
