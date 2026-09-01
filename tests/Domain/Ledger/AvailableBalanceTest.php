<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\AvailableBalance;
use Ledger\Domain\Ledger\Hold;
use Ledger\Domain\Ledger\HoldRegistry;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AvailableBalance::class)]
final class AvailableBalanceTest extends TestCase
{
    private const ACC1 = 'ACC-001';
    private const ACC2 = 'ACC-002';

    private Ledger $ledger;
    private HoldRegistry $holds;
    private AvailableBalance $available;

    protected function setUp(): void
    {
        $this->ledger = new Ledger(
            Account::emptyIn(self::ACC1, Currency::AED),
            Account::emptyIn(self::ACC2, Currency::BHD),
        );
        $this->holds = new HoldRegistry();
        $this->available = new AvailableBalance($this->ledger, $this->holds);
    }

    private static function id(string $value = self::ACC1): AccountId
    {
        return AccountId::of($value);
    }

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private function credit(string $amount, int $valueDate, int $bookedDay, string $account = self::ACC1): void
    {
        $currency = $account === self::ACC2 ? Currency::BHD : Currency::AED;

        $this->ledger->append(LedgerEntry::credit(
            self::id($account),
            Money::of($amount, $currency),
            self::day($valueDate),
            self::day($bookedDay),
        ));
    }

    private function debit(string $amount, int $valueDate, int $bookedDay): void
    {
        $this->ledger->append(
            LedgerEntry::debit(self::id(), Money::of($amount, Currency::AED), self::day($valueDate), self::day($bookedDay)),
        );
    }

    private function hold(string $id, string $amount, int $day, string $account = self::ACC1): Hold
    {
        $currency = $account === self::ACC2 ? Currency::BHD : Currency::AED;

        $hold = Hold::place(
            AuthorizationId::of($id),
            self::id($account),
            Money::of($amount, $currency),
            self::day($day),
        );
        $this->holds->place($hold);

        return $hold;
    }

    private function assertAvailable(string $expected, int $day, string $account = self::ACC1): void
    {
        self::assertSame(
            $expected,
            $this->available->on(self::id($account), self::day($day))->format(),
            sprintf('available balance on Day %d', $day),
        );
    }

    // ====================================================

    public function testEqualsTheLedgerBalanceWhenNothingIsHeld(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->debit('950.00', 1, 1);

        $this->assertAvailable('250.00', 1);
    }

    public function testIsZeroOnAnUntouchedAccountInItsOwnPrecision(): void
    {
        $this->assertAvailable('0.00', 1);
        $this->assertAvailable('0.000', 1, self::ACC2);
    }

    /**
     * ACCEPTANCE CRITERION 5, accepted, in its testable half.
     *
     * "If Auth-B is approved, its hold reduces available balance but not ledger balance."
     *
     * Auth-B is never approved, so the criterion is vacuously true as written — see
     * AMBIGUITIES.md §12. The rule it states is nonetheless correct, and this is it: the
     * ledger balance does not move by one minor unit when a hold is placed.
     */
    public function testAHoldReducesAvailableBalanceAndNotLedgerBalance(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->debit('950.00', 1, 1);

        $this->hold('Auth-A', '200.00', 2);

        $this->assertAvailable('50.00', 2);
        self::assertSame(
            '250.00',
            $this->ledger->balanceAsOf(self::id(), self::day(2), self::day(2))->format(),
            'the ledger balance is untouched by the hold',
        );
        self::assertSame(2, $this->ledger->count(), 'and no entry was written for it');
    }

    public function testReleasingAHoldReturnsTheFunds(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->hold('Auth-A', '200.00', 2);
        $this->assertAvailable('1000.00', 2);

        $this->holds->release(AuthorizationId::of('Auth-A'), self::day(4));

        $this->assertAvailable('1200.00', 4);
    }

    public function testEveryActiveHoldCounts(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->hold('Auth-A', '200.00', 2);
        $this->hold('Auth-B', '90.00', 2);

        $this->assertAvailable('910.00', 2);
    }

    /**
     * Available balance is a question about now, and "now" moves both bitemporal coordinates
     * together. E7 is booked on Day 5 with value_date Day 2: on Day 4 the ledger has not heard
     * of it and available stands at 465.00; on Day 5 it has, and available is -155.00.
     *
     * This is the whole reason Auth-B declines. Read available balance off the wrong day and
     * the authorization approves against money that a backdated debit has already spent.
     */
    public function testUsesWhatTheLedgerKnowsOnTheDayItIsAsked(): void
    {
        $this->credit('1200.00', 1, 1);           // E1
        $this->debit('950.00', 1, 1);             // E2
        $this->credit('400.00', 3, 3);            // E4
        $this->ledger->append(LedgerEntry::settlement(
            self::id(), Money::of('185.00', Currency::AED), self::day(4), self::day(4),
        ));                                       // E5

        $this->assertAvailable('465.00', 4);

        $this->debit('620.00', 2, 5);             // E7 — backdated to Day 2, booked on Day 5

        $this->assertAvailable('465.00', 4);      // Day 4 still does not know
        $this->assertAvailable('-155.00', 5);     // Day 5 does
    }

    /**
     * Both coordinates move together, and neither subsumes the other. A credit dated Day 6 is
     * not available on Day 5 even though the ledger already knows of it; a debit booked on
     * Day 5 is not available knowledge on Day 4 even though it belongs to Day 2.
     */
    public function testBothDateCoordinatesMoveWithTheDayAsked(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->credit('500.00', 6, 5);   // known on Day 5, but belongs to Day 6
        $this->debit('620.00', 2, 5);    // belongs to Day 2, but not known until Day 5

        $this->assertAvailable('1200.00', 4);   // knows neither
        $this->assertAvailable('580.00', 5);    // the debit counts; the Day 6 credit does not
        $this->assertAvailable('1080.00', 6);   // both count
    }

    /**
     * The hold side of the same question, which this test used to be named for and never
     * exercised — it placed no holds at all, so the untested half was the half that was wrong.
     *
     * A hold placed on Day 5 reserves nothing on Day 2. Subtracting it anyway would take a
     * day-scoped balance and reduce it by an unscoped total, which is the dimension-collapsing
     * mistake the whole design refuses on the entry side.
     */
    public function testAHoldPlacedLaterDoesNotReduceAnEarlierDay(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->hold('Auth-B', '200.00', 5);

        $this->assertAvailable('1200.00', 2);
        $this->assertAvailable('1200.00', 4);
        $this->assertAvailable('1000.00', 5);
        $this->assertAvailable('1000.00', 6);
    }

    /**
     * And a released hold stops counting from the day it was released, not retroactively. On
     * Day 3 the 200.00 was genuinely reserved; asking about Day 3 has to say so, even after
     * Day 4 has given it back.
     */
    public function testAReleasedHoldStillCountsOnTheDaysItWasLive(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->hold('Auth-A', '200.00', 2);
        $this->holds->release(AuthorizationId::of('Auth-A'), self::day(4));

        $this->assertAvailable('1200.00', 1);   // placed on Day 2 — not yet
        $this->assertAvailable('1000.00', 2);
        $this->assertAvailable('1000.00', 3);
        $this->assertAvailable('1200.00', 4);     // released
    }

    /** Nothing clamps available balance at zero. Auth-B is declined against -155.00. */
    public function testCanBeNegative(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->debit('950.00', 1, 1);
        $this->debit('620.00', 2, 5);

        $this->assertAvailable('-370.00', 5);
    }

    public function testHoldsOnOneAccountDoNotReduceAnothers(): void
    {
        $this->credit('1200.00', 1, 1);
        $this->credit('10.000', 5, 5, self::ACC2);
        $this->hold('Auth-A', '200.00', 5);

        $this->assertAvailable('1000.00', 5);
        $this->assertAvailable('10.000', 5, self::ACC2);
    }
}
