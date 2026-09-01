<?php

declare(strict_types=1);

namespace Ledger\Tests\Support;

use Ledger\Domain\Event\AuthorizationEvent;
use Ledger\Domain\Event\CreditEvent;
use Ledger\Domain\Event\DebitEvent;
use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\EventStream;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Event\SettlementEvent;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;

/**
 * The brief's ten events, transcribed once.
 *
 * A test fixture for now. When the CLI arrives it moves to an Infrastructure event source and
 * this delegates to it, so the stream is never written down twice — a second transcription is
 * a second chance to mistype 620.00.
 */
final class AssessmentStream
{
    public const ACC1 = 'ACC-001';
    public const ACC2 = 'ACC-002';

    /** In the order the brief lists them, E10 out of day order and all. */
    public static function asListed(): EventStream
    {
        $acc1 = AccountId::of(self::ACC1);
        $acc2 = AccountId::of(self::ACC2);

        return new EventStream(
            // E1 — Day 1 — CREDIT — ACC-001 AED 1,200.00 — value_date Day 1
            new CreditEvent(EventId::of('E1'), $acc1, self::aed('1200.00'), self::day(1), self::day(1)),
            // E2 — Day 1 — DEBIT — ACC-001 AED 950.00 — value_date Day 1
            new DebitEvent(EventId::of('E2'), $acc1, self::aed('950.00'), self::day(1), self::day(1)),
            // E3 — Day 2 — AUTHORIZATION — ACC-001 Auth-A hold AED 200.00 — value_date Day 2
            new AuthorizationEvent(
                EventId::of('E3'), $acc1, AuthorizationId::of('Auth-A'), self::aed('200.00'),
                self::day(2), self::day(2),
            ),
            // E4 — Day 3 — CREDIT — ACC-001 AED 400.00 — value_date Day 3
            new CreditEvent(EventId::of('E4'), $acc1, self::aed('400.00'), self::day(3), self::day(3)),
            // E5 — Day 4 — SETTLEMENT — ACC-001 Auth-A settles for AED 185.00 — value_date Day 4
            new SettlementEvent(
                EventId::of('E5'), $acc1, AuthorizationId::of('Auth-A'), self::aed('185.00'),
                self::day(4), self::day(4),
            ),
            // E6 — Day 4 — SETTLEMENT — ACC-001 Auth-Z settles for AED 180.00 — value_date Day 4
            //      (Auth-Z has no preceding authorization event)
            new SettlementEvent(
                EventId::of('E6'), $acc1, AuthorizationId::of('Auth-Z'), self::aed('180.00'),
                self::day(4), self::day(4),
            ),
            // E7 — Day 5 — DEBIT — ACC-001 AED 620.00 — value_date Day 2
            new DebitEvent(EventId::of('E7'), $acc1, self::aed('620.00'), self::day(5), self::day(2)),
            // E8 — Day 5 — AUTHORIZATION — ACC-001 Auth-B hold AED 90.00 — value_date Day 5
            new AuthorizationEvent(
                EventId::of('E8'), $acc1, AuthorizationId::of('Auth-B'), self::aed('90.00'),
                self::day(5), self::day(5),
            ),
            // E9 — Day 6 — REVERSAL — ACC-001 reverses E7 — value_date Day 2
            new ReversalEvent(EventId::of('E9'), $acc1, EventId::of('E7'), self::day(6), self::day(2)),
            // E10 — Day 5 — CREDIT — ACC-002 BHD 10.000, three equal instalments — value_date Day 5
            new CreditEvent(
                EventId::of('E10'), $acc2, Money::of('10.000', Currency::BHD),
                self::day(5), self::day(5), instalments: 3,
            ),
        );
    }

    private static function day(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }
}
