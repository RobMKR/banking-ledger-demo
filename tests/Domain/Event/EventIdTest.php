<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Event;

use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\Exception\InvalidEventId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventId::class)]
final class EventIdTest extends TestCase
{
    public function testCarriesItsValue(): void
    {
        self::assertSame('E7', EventId::of('E7')->value);
        self::assertSame('E7', (string) EventId::of('E7'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('E10', EventId::of(' E10 ')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function blankValues(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
        yield 'tab' => ["\t"];
    }

    #[DataProvider('blankValues')]
    public function testRefusesABlankId(string $value): void
    {
        $this->expectException(InvalidEventId::class);

        EventId::of($value);
    }

    /**
     * Equality is the whole of the duplicate guard: two events with one id are the same event,
     * whatever else they carry. E10 and E1 must not collide on a prefix match.
     */
    public function testComparesByExactValue(): void
    {
        self::assertTrue(EventId::of('E7')->equals(EventId::of('E7')));
        self::assertFalse(EventId::of('E1')->equals(EventId::of('E10')));
    }
}
