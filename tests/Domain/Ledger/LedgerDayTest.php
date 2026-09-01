<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\Exception\InvalidLedgerDay;
use Ledger\Domain\Ledger\LedgerDay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerDay::class)]
final class LedgerDayTest extends TestCase
{
    public function testCountsFromOne(): void
    {
        self::assertSame(1, LedgerDay::of(1)->number);
        self::assertSame('Day 6', (string) LedgerDay::of(6));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidDays(): iterable
    {
        yield 'zero'     => [0];
        yield 'negative'  => [-1];
    }

    #[DataProvider('invalidDays')]
    public function testRefusesDaysBeforeTheWindowStarts(int $number): void
    {
        $this->expectException(InvalidLedgerDay::class);

        LedgerDay::of($number);
    }

    public function testOrdersDays(): void
    {
        $two = LedgerDay::of(2);
        $five = LedgerDay::of(5);

        self::assertTrue($two->isBefore($five));
        self::assertTrue($five->isAfter($two));
        self::assertTrue($two->isOnOrBefore($five));
        self::assertTrue($two->isOnOrBefore(LedgerDay::of(2)));
        self::assertFalse($five->isOnOrBefore($two));
        self::assertSame(-1, $two->compareTo($five));
        self::assertTrue($two->equals(LedgerDay::of(2)));
    }

    public function testBuildsTheAssessmentWindow(): void
    {
        $window = LedgerDay::through(1, 6);

        self::assertCount(6, $window);
        self::assertSame([1, 2, 3, 4, 5, 6], array_map(static fn (LedgerDay $d): int => $d->number, $window));
    }

    public function testRefusesAnEmptyRange(): void
    {
        $this->expectException(InvalidLedgerDay::class);

        LedgerDay::through(6, 1);
    }
}
