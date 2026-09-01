<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Rule;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Money\Rate;
use Ledger\Domain\Rule\InterestAccrualRule;
use Ledger\Domain\Service\InterestSchedule;
use Ledger\Tests\Support\AssessmentLedger;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InterestAccrualRule::class)]
final class InterestAccrualRuleTest extends TestCase
{
    private static function acc(string $id = AssessmentStream::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    private static function d(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    /** The full window, capitalized. @return array{AssessmentLedger, InterestAccrualRule} */
    private static function capitalizedWindow(): array
    {
        $harness = AssessmentLedger::throughDay(6);
        $rule = new InterestAccrualRule(
            $harness->ledger,
            new InterestSchedule($harness->ledger, Rate::fromBasisPoints(4)),
        );

        return [$harness, $rule];
    }

    // ==================================================== the closing figures

    /**
     * The number the whole exercise arrives at.
     *
     * 390.00 after three fees and E9's reversal, plus 0.93 of capitalized interest. Every
     * resolved ambiguity is baked into it: retroactive assessment, orphan rejection, fees
     * standing, accruals restated. Change any one and this figure moves — AMBIGUITIES.md
     * carries the four alternatives it would have become.
     */
    public function testAccountOneClosesAtThreeNinetyNinetyThree(): void
    {
        [$harness, $rule] = self::capitalizedWindow();

        $entry = $rule->capitalize(self::acc(), self::d(6));

        self::assertSame('0.93', $entry?->amount->format());
        self::assertSame('390.93', $harness->balanceOn(6, 6));
    }

    public function testAccountTwoClosesAtTenPointZeroZeroEight(): void
    {
        [$harness, $rule] = self::capitalizedWindow();

        $rule->capitalize(self::acc(AssessmentStream::ACC2), self::d(6));

        self::assertSame('10.008', $harness->balanceOn(6, 6, AssessmentStream::ACC2));
    }

    /** "Accruals capitalize as a single credit at end of Day 6" — one entry, not six. */
    public function testInterestArrivesAsOneCreditOnTheFinalDay(): void
    {
        [$harness, $rule] = self::capitalizedWindow();

        $rule->capitalize(self::acc(), self::d(6));

        $interest = $harness->ledger->entriesOfType(self::acc(), EntryType::INTEREST);
        self::assertCount(1, $interest);
        self::assertSame(6, $interest[0]->valueDate->number);
        self::assertSame(6, $interest[0]->bookedDay->number);
        self::assertSame('INTEREST', $interest[0]->reference);
    }

    /**
     * Capitalizing on Day 6 alone is what cuts the circularity in AMBIGUITIES.md §8: the credit
     * lands after every accrual is computed, so no day's interest is ever earned on interest.
     * Days 1 to 5 are untouched by it.
     */
    public function testTheCreditDoesNotDisturbAnyEarlierDay(): void
    {
        [$harness, $rule] = self::capitalizedWindow();
        $before = array_map(static fn (int $d): string => $harness->balanceOn($d, 6), [1, 2, 3, 4, 5]);

        $rule->capitalize(self::acc(), self::d(6));

        $after = array_map(static fn (int $d): string => $harness->balanceOn($d, 6), [1, 2, 3, 4, 5]);
        self::assertSame($before, $after);
        self::assertSame(['250.00', '225.00', '625.00', '415.00', '390.00'], $after);
    }

    // ==================================================== guards

    public function testInterestIsNotCapitalizedTwice(): void
    {
        [$harness, $rule] = self::capitalizedWindow();
        $rule->capitalize(self::acc(), self::d(6));

        self::assertNull($rule->capitalize(self::acc(), self::d(6)));
        self::assertCount(1, $harness->ledger->entriesOfType(self::acc(), EntryType::INTEREST));
        self::assertSame('390.93', $harness->balanceOn(6, 6), 'not 391.86');
    }

    /** An account that earned nothing gets no entry. A zero credit records something that did not happen. */
    public function testAnAccountThatEarnedNothingGetsNoEntryAtAll(): void
    {
        $ledger = new Ledger(Account::emptyIn(AssessmentStream::ACC1, Currency::AED));
        $ledger->append(LedgerEntry::debit(
            self::acc(), Money::of('500.00', Currency::AED), self::d(1), self::d(1), 'X',
        ));
        $rule = new InterestAccrualRule($ledger, new InterestSchedule($ledger, Rate::fromBasisPoints(4)));

        self::assertNull($rule->capitalize(self::acc(), self::d(6)));
        self::assertSame([], $ledger->entriesOfType(self::acc(), EntryType::INTEREST));
    }
}
