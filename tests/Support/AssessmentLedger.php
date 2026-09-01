<?php

declare(strict_types=1);

namespace Ledger\Tests\Support;

use Ledger\Domain\Event\EventId;
use Ledger\Domain\Event\ReversalEvent;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Allocator;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Rule\OverdraftFeeRule;
use Ledger\Domain\Rule\ReversalRule;

/**
 * The stream replayed day by day, with the daily close running as each day ends.
 *
 * A narrower stand-in for the replay engine: it closes days in order and applies fees, but
 * capitalizes no interest and processes no authorizations. That is deliberate — the fee
 * cascade only appears if days close in order, and these tests want that sequence without the
 * rest of the engine's behaviour in the way.
 *
 * The engine exists, and the golden test pins both to the same figures.
 * The events that post nothing are absent by design: E3 and E8 are authorizations, E6 is the
 * rejected orphan.
 */
final class AssessmentLedger
{
    public const FEE = '25.00';

    private function __construct(
        public Ledger $ledger,
        public OverdraftFeeRule $fees,
    ) {
    }

    public static function throughDay(int $lastDay): self
    {
        $ledger = new Ledger(
            Account::emptyIn(AssessmentStream::ACC1, Currency::AED),
            Account::emptyIn(AssessmentStream::ACC2, Currency::BHD),
        );
        $self = new self($ledger, new OverdraftFeeRule($ledger, self::aed(self::FEE)));

        foreach (range(1, $lastDay) as $day) {
            $self->postEventsBookedOn($day);
            $self->fees->assessThrough(self::acc1(), LedgerDay::of($day));
        }

        return $self;
    }

    public function balanceOn(int $valueDate, ?int $knownAsOf = null, string $account = AssessmentStream::ACC1): string
    {
        $knownAsOf ??= $valueDate;

        return $this->ledger->balanceAsOf(
            AccountId::of($account),
            LedgerDay::of($valueDate),
            LedgerDay::of($knownAsOf),
        )->format();
    }

    private function postEventsBookedOn(int $day): void
    {
        $acc1 = self::acc1();

        match ($day) {
            1 => $this->post(
                LedgerEntry::credit($acc1, self::aed('1200.00'), self::d(1), self::d(1), 'E1'),
                LedgerEntry::debit($acc1, self::aed('950.00'), self::d(1), self::d(1), 'E2'),
            ),
            // E3 — Auth-A approved. An authorization posts nothing.
            2 => null,
            3 => $this->post(LedgerEntry::credit($acc1, self::aed('400.00'), self::d(3), self::d(3), 'E4')),
            // E5 settles Auth-A. E6 names Auth-Z and is rejected, so it posts nothing.
            4 => $this->post(LedgerEntry::settlement($acc1, self::aed('185.00'), self::d(4), self::d(4), 'E5')),
            // E7 is backdated to Day 2. E8 — Auth-B — is declined and posts nothing.
            // E10 credits ACC-002 in three instalments, allocated by largest remainder.
            5 => $this->postDayFive(),
            6 => $this->reverseE7(),
            default => null,
        };
    }

    private function postDayFive(): void
    {
        $this->post(LedgerEntry::debit(self::acc1(), self::aed('620.00'), self::d(2), self::d(5), 'E7'));

        $acc2 = AccountId::of(AssessmentStream::ACC2);
        $instalments = Allocator::intoEqualParts(Money::of('10.000', Currency::BHD), 3);

        foreach ($instalments as $i => $part) {
            $this->post(LedgerEntry::credit($acc2, $part, self::d(5), self::d(5), sprintf('E10.%d', $i + 1)));
        }
    }

    private function reverseE7(): void
    {
        (new ReversalRule($this->ledger))->apply(new ReversalEvent(
            EventId::of('E9'),
            self::acc1(),
            EventId::of('E7'),
            self::d(6),
            self::d(2),
        ));
    }

    private function post(LedgerEntry ...$entries): void
    {
        foreach ($entries as $entry) {
            $this->ledger->append($entry);
        }
    }

    private static function acc1(): AccountId
    {
        return AccountId::of(AssessmentStream::ACC1);
    }

    private static function d(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $amount): Money
    {
        return Money::of($amount, Currency::AED);
    }
}
