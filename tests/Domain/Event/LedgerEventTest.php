<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Event;

use Ledger\Domain\Event\AuthorizationEvent;
use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\DebitEvent;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventType;
use Ledger\Domain\Event\Exception\InvalidEvent;
use Ledger\Domain\Event\LedgerEvent;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Event\SettlementEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerEvent::class)]
#[CoversClass(CreditEvent::class)]
#[CoversClass(DebitEvent::class)]
#[CoversClass(AuthorizationEvent::class)]
#[CoversClass(SettlementEvent::class)]
#[CoversClass(ReversalEvent::class)]
#[CoversClass(EventType::class)]
final class LedgerEventTest extends TestCase
{
    private static function acc(string $id = AssessmentStream::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }

    // ==================================================== each type

    public function testACreditCarriesItsAmountAndDays(): void
    {
        $e1 = new CreditEvent(EventId::of('E1'), self::acc(), self::aed('1200.00'), self::day(1), self::day(1));

        self::assertSame(EventType::CREDIT, $e1->type());
        self::assertSame('1200.00', $e1->amount->format());
        self::assertSame('CREDIT 1200.00 AED', $e1->describe());
        self::assertSame(1, $e1->instalments);
        self::assertFalse($e1->isSplit());
    }

    /**
     * E10 is a credit with a split count, not a different kind of event. A plain credit is the
     * same object with n = 1, so the posting path does not fork.
     */
    public function testACreditCanAskToBeSplitIntoInstalments(): void
    {
        $e10 = new CreditEvent(
            EventId::of('E10'), self::acc(AssessmentStream::ACC2), Money::of('10.000', Currency::BHD),
            self::day(5), self::day(5), instalments: 3,
        );

        self::assertTrue($e10->isSplit());
        self::assertSame(3, $e10->instalments);
        self::assertSame('CREDIT 10.000 BHD in 3 instalments', $e10->describe());
    }

    public function testADebitCarriesItsAmount(): void
    {
        $e7 = new DebitEvent(EventId::of('E7'), self::acc(), self::aed('620.00'), self::day(5), self::day(2));

        self::assertSame(EventType::DEBIT, $e7->type());
        self::assertSame('DEBIT 620.00 AED', $e7->describe());
    }

    public function testAnAuthorizationCarriesTheIdItWillBeSettledUnder(): void
    {
        $e3 = new AuthorizationEvent(
            EventId::of('E3'), self::acc(), AuthorizationId::of('Auth-A'), self::aed('200.00'),
            self::day(2), self::day(2),
        );

        self::assertSame(EventType::AUTHORIZATION, $e3->type());
        self::assertSame('Auth-A', $e3->authorization->value);
        self::assertSame('AUTHORIZATION Auth-A holding 200.00 AED', $e3->describe());
    }

    public function testASettlementNamesItsAuthorizationAndItsOwnAmount(): void
    {
        $e5 = new SettlementEvent(
            EventId::of('E5'), self::acc(), AuthorizationId::of('Auth-A'), self::aed('185.00'),
            self::day(4), self::day(4),
        );

        self::assertSame(EventType::SETTLEMENT, $e5->type());
        self::assertSame('SETTLEMENT Auth-A for 185.00 AED', $e5->describe());
    }

    /**
     * A reversal carries no amount. It names the event it undoes, and the figure comes from
     * whatever that one posted — the only way the pair is guaranteed to net to zero.
     */
    public function testAReversalNamesItsTargetAndCarriesNoAmountOfItsOwn(): void
    {
        $e9 = new ReversalEvent(EventId::of('E9'), self::acc(), EventId::of('E7'), self::day(6), self::day(2));

        self::assertSame(EventType::REVERSAL, $e9->type());
        self::assertSame('E7', $e9->reverses->value);
        self::assertSame('REVERSAL of E7', $e9->describe());
        self::assertFalse(property_exists($e9, 'amount'), 'a reversal must not restate the amount');
    }

    // ==================================================== the two days

    /**
     * Only E7 and E9 are backdated. Every other event belongs to the day it arrives on, which
     * is why eight of the ten could be processed by a ledger that had never heard of value
     * dates and the remaining two could not.
     */
    public function testOnlyTheTwoBackdatedEventsReportThemselvesAsBackdated(): void
    {
        $backdated = [];
        foreach (AssessmentStream::asListed()->asListed() as $event) {
            if ($event->isBackdated()) {
                $backdated[] = $event->id->value;
            }
        }

        self::assertSame(['E7', 'E9'], $backdated);
    }

    public function testAnEventOnItsOwnDayIsNotBackdated(): void
    {
        $e1 = new CreditEvent(EventId::of('E1'), self::acc(), self::aed('1200.00'), self::day(1), self::day(1));

        self::assertFalse($e1->isBackdated());
    }

    // ==================================================== validation

    /**
     * Direction lives in the type, never in the sign. "CREDIT -100.00" would let one figure
     * mean two opposite things depending on which field a reader trusts.
     *
     * @return iterable<string, array{callable(Money): LedgerEvent}>
     */
    public static function amountCarryingEvents(): iterable
    {
        $acc = AccountId::of(AssessmentStream::ACC1);
        $day = LedgerDay::of(1);
        $auth = AuthorizationId::of('Auth-A');

        yield 'credit' => [static fn (Money $m): LedgerEvent
            => new CreditEvent(EventId::of('E1'), $acc, $m, $day, $day)];
        yield 'debit' => [static fn (Money $m): LedgerEvent
            => new DebitEvent(EventId::of('E2'), $acc, $m, $day, $day)];
        yield 'authorization' => [static fn (Money $m): LedgerEvent
            => new AuthorizationEvent(EventId::of('E3'), $acc, $auth, $m, $day, $day)];
        yield 'settlement' => [static fn (Money $m): LedgerEvent
            => new SettlementEvent(EventId::of('E5'), $acc, $auth, $m, $day, $day)];
    }

    #[DataProvider('amountCarryingEvents')]
    public function testRefusesANegativeAmount(callable $build): void
    {
        $this->expectException(InvalidEvent::class);

        $build(self::aed('-100.00'));
    }

    #[DataProvider('amountCarryingEvents')]
    public function testRefusesAZeroAmount(callable $build): void
    {
        $this->expectException(InvalidEvent::class);

        $build(self::aed('0.00'));
    }

    /** @return iterable<string, array{int}> */
    public static function impossibleInstalmentCounts(): iterable
    {
        yield 'none' => [0];
        yield 'negative' => [-3];
    }

    #[DataProvider('impossibleInstalmentCounts')]
    public function testRefusesACreditThatPostsFewerThanOnce(int $instalments): void
    {
        $this->expectException(InvalidEvent::class);

        new CreditEvent(
            EventId::of('E10'), self::acc(), self::aed('10.00'), self::day(5), self::day(5), $instalments,
        );
    }

    // ==================================================== event type

    public function testOnlyAnAuthorizationMovesNoMoney(): void
    {
        $still = array_filter(EventType::cases(), static fn (EventType $t): bool => !$t->movesMoney());

        self::assertSame([EventType::AUTHORIZATION], array_values($still));
    }

    /**
     * Five event types, six entry types. The extra two are the overdraft fee and the interest
     * credit — entries the ledger raises for itself at daily close, which nothing outside can
     * ask for. If an event type is ever added for one of them, that is a design change worth
     * noticing rather than a detail.
     */
    public function testThereIsNoEventForTheEntriesTheLedgerRaisesItself(): void
    {
        $names = array_map(static fn (EventType $t): string => $t->value, EventType::cases());

        self::assertNotContains('OVERDRAFT_FEE', $names);
        self::assertNotContains('INTEREST', $names);
        self::assertCount(5, $names);
    }
}
