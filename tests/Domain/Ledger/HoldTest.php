<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\Exception\HoldAlreadyReleased;
use Ledger\Domain\Ledger\Exception\InvalidHold;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hold::class)]
final class HoldTest extends TestCase
{
    private static function authA(string $amount = '200.00', int $placedOn = 2): Hold
    {
        return Hold::place(
            AuthorizationId::of('Auth-A'),
            AccountId::of('ACC-001'),
            Money::of($amount, Currency::AED),
            LedgerDay::of($placedOn),
        );
    }

    public function testAPlacedHoldIsActiveAndCarriesItsPlacementDay(): void
    {
        $hold = self::authA();

        self::assertTrue($hold->isActive());
        self::assertNull($hold->releasedOn);
        self::assertSame('200.00', $hold->amount->format());
        self::assertSame(2, $hold->placedOn->number);
    }

    /**
     * Releasing returns a copy. The original instance is not mutated, so a caller that kept a
     * reference to the live hold cannot be surprised into thinking funds are still reserved
     * or, worse, that they never were.
     */
    public function testReleasingReturnsACopyAndLeavesTheOriginalUntouched(): void
    {
        $held = self::authA();
        $released = $held->released(LedgerDay::of(4));

        self::assertNotSame($held, $released);
        self::assertTrue($held->isActive());
        self::assertFalse($released->isActive());
        self::assertSame(4, $released->releasedOn?->number);

        // Everything else survives the release: a released hold still says what it was.
        self::assertTrue($released->authorization->equals($held->authorization));
        self::assertSame('200.00', $released->amount->format());
        self::assertSame(2, $released->placedOn->number);
    }

    public function testAHoldCanBeReleasedOnTheDayItWasPlaced(): void
    {
        self::assertFalse(self::authA()->released(LedgerDay::of(2))->isActive());
    }

    public function testRefusesToBeReleasedBeforeItWasPlaced(): void
    {
        $this->expectException(InvalidHold::class);

        self::authA()->released(LedgerDay::of(1));
    }

    /** Releasing twice would hand back the reserved funds twice over. */
    public function testRefusesASecondRelease(): void
    {
        $released = self::authA()->released(LedgerDay::of(4));

        $this->expectException(HoldAlreadyReleased::class);
        $released->released(LedgerDay::of(5));
    }

    /**
     * A hold reserves funds. A zero hold reserves nothing, and a negative one would *raise*
     * available balance — an authorization that manufactures headroom instead of consuming it.
     *
     * @return iterable<string, array{string}>
     */
    public static function amountsThatReserveNothing(): iterable
    {
        yield 'zero' => ['0.00'];
        yield 'negative' => ['-200.00'];
    }

    #[DataProvider('amountsThatReserveNothing')]
    public function testRefusesAnAmountThatReservesNothing(string $amount): void
    {
        $this->expectException(InvalidHold::class);

        self::authA($amount);
    }

    public function testKnowsWhichAccountItIsAgainst(): void
    {
        $hold = self::authA();

        self::assertTrue($hold->belongsTo(AccountId::of('ACC-001')));
        self::assertFalse($hold->belongsTo(AccountId::of('ACC-002')));
    }
}
