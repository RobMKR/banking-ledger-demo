<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Money;

use Ledger\Domain\Money\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Currency::class)]
final class CurrencyTest extends TestCase
{
    public function testAedHasTwoDecimalPlaces(): void
    {
        self::assertSame(2, Currency::AED->exponent());
        self::assertSame(100, Currency::AED->minorUnitScale());
    }

    /** BHD's third decimal place is the reason precision is per-currency and not global. */
    public function testBhdHasThreeDecimalPlaces(): void
    {
        self::assertSame(3, Currency::BHD->exponent());
        self::assertSame(1000, Currency::BHD->minorUnitScale());
    }

    public function testOnlyTheTwoCurrenciesInTheBriefExist(): void
    {
        self::assertSame(['AED', 'BHD'], array_column(Currency::cases(), 'value'));
    }
}
