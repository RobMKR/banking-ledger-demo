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
use Ledger\Domain\Rule\OverdraftFeeRule;
use Ledger\Tests\Support\AssessmentLedger;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OverdraftFeeRule::class)]
final class OverdraftFeeRuleTest extends TestCase
{
    private static function acc(): AccountId
    {
        return AccountId::of(AssessmentStream::ACC1);
    }

    private static function d(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function aed(string $a): Money
    {
        return Money::of($a, Currency::AED);
    }

    /** @return list<int> the value dates the fees were booked against */
    private static function feeDays(Ledger $ledger): array
    {
        return array_map(
            static fn (LedgerEntry $e): int => $e->valueDate->number,
            $ledger->entriesOfType(self::acc(), EntryType::OVERDRAFT_FEE),
        );
    }

    private static function bareLedger(): Ledger
    {
        return new Ledger(Account::emptyIn(AssessmentStream::ACC1, Currency::AED));
    }

    private static function ruleOn(Ledger $ledger): OverdraftFeeRule
    {
        return new OverdraftFeeRule($ledger, self::aed('25.00'));
    }

    // ==================================================== the cascade

    /**
     * The whole of the retroactive reading, in one table.
     *
     * E7 arrives on Day 5 with value_date Day 2 and drags three already-closed days negative.
     * A single ascending pass assesses them, and each fee it raises is part of the balance the
     * next day is judged against — which is what turns one backdated debit into three charges.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function balancesAtCloseOfDayFive(): iterable
    {
        yield 'D1 never negative'     => [1, '250.00'];
        yield 'D2 -370.00 plus a fee' => [2, '-395.00'];
        yield 'D3 survives'           => [3, '5.00'];
        yield 'D4 -155.00 plus two'   => [4, '-205.00'];
        yield 'D5 plus three'         => [5, '-230.00'];
    }

    #[DataProvider('balancesAtCloseOfDayFive')]
    public function testTheCascadeAtCloseOfDayFive(int $valueDate, string $expected): void
    {
        self::assertSame($expected, AssessmentLedger::throughDay(5)->balanceOn($valueDate, 5));
    }

    public function testThreeFeesAreRaisedOnDaysTwoFourAndFive(): void
    {
        $harness = AssessmentLedger::throughDay(5);

        self::assertSame([2, 4, 5], self::feeDays($harness->ledger));
    }

    /**
     * Day 3 is the near miss, and it is why NUMBERS.md calls the 25.00 fee load-bearing within
     * 5.00: it closes at exactly +5.00 once Day 2's fee lands. A fee above 30.00 would drive it
     * negative and raise a fourth.
     */
    public function testDayThreeSurvivesTheCascadeByFivePounds(): void
    {
        self::assertSame('5.00', AssessmentLedger::throughDay(5)->balanceOn(3, 5));
    }

    public function testNoFeeIsRaisedBeforeE7Arrives(): void
    {
        self::assertSame([], self::feeDays(AssessmentLedger::throughDay(4)->ledger));
    }

    public function testAFeeIsBookedAtTheDayItWasAssessedForNotTheDayItWasRaised(): void
    {
        $fees = AssessmentLedger::throughDay(5)->ledger->entriesOfType(self::acc(), EntryType::OVERDRAFT_FEE);

        self::assertSame(2, $fees[0]->valueDate->number, 'the day whose balance was negative');
        self::assertSame(5, $fees[0]->bookedDay->number, 'the day the assessment ran');
        self::assertSame('-25.00', $fees[0]->amount->format());
        self::assertSame('FEE-D2', $fees[0]->reference);
    }

    // ==================================================== the fixpoint

    /**
     * A single ascending pass *is* the fixpoint, and this is the proof that carries it — not a
     * convergence guard or an iteration cap at runtime.
     *
     * A fee books with value_date equal to the day whose balance was negative, so it can only
     * ever affect days at or after that day. Nothing it does can make an earlier day newly
     * negative, so there is no second round to run. Running one anyway changes nothing.
     */
    public function testASecondAssessmentPassIsANoOp(): void
    {
        $harness = AssessmentLedger::throughDay(5);
        $entriesAfterFirstPass = $harness->ledger->count();

        $raised = $harness->fees->assessThrough(self::acc(), self::d(5));

        self::assertSame([], $raised);
        self::assertSame($entriesAfterFirstPass, $harness->ledger->count());
        self::assertSame('-230.00', $harness->balanceOn(5, 5));
    }

    public function testTenMorePassesChangeNothingEither(): void
    {
        $harness = AssessmentLedger::throughDay(6);
        $before = $harness->ledger->count();

        for ($i = 0; $i < 10; $i++) {
            $harness->fees->assessThrough(self::acc(), self::d(6));
        }

        self::assertSame($before, $harness->ledger->count());
        self::assertSame([2, 4, 5], self::feeDays($harness->ledger));
    }

    // ==================================================== the rule itself

    public function testOnlyOneFeePerDayPerAccount(): void
    {
        $ledger = self::bareLedger();
        $ledger->append(LedgerEntry::debit(self::acc(), self::aed('10.00'), self::d(1), self::d(1), 'X'));
        $rule = self::ruleOn($ledger);

        $rule->assessThrough(self::acc(), self::d(1));
        $rule->assessThrough(self::acc(), self::d(1));

        self::assertSame([1], self::feeDays($ledger));
    }

    /** "when that day's closing ledger balance is negative" — zero is not negative. */
    public function testADayClosingAtExactlyZeroIsNotCharged(): void
    {
        $ledger = self::bareLedger();
        $ledger->append(LedgerEntry::credit(self::acc(), self::aed('10.00'), self::d(1), self::d(1), 'X'));
        $ledger->append(LedgerEntry::debit(self::acc(), self::aed('10.00'), self::d(1), self::d(1), 'Y'));

        self::ruleOn($ledger)->assessThrough(self::acc(), self::d(1));

        self::assertSame([], self::feeDays($ledger));
    }

    public function testOneMinorUnitBelowZeroIsCharged(): void
    {
        $ledger = self::bareLedger();
        $ledger->append(LedgerEntry::credit(self::acc(), self::aed('10.00'), self::d(1), self::d(1), 'X'));
        $ledger->append(LedgerEntry::debit(self::acc(), self::aed('10.01'), self::d(1), self::d(1), 'Y'));

        self::ruleOn($ledger)->assessThrough(self::acc(), self::d(1));

        self::assertSame([1], self::feeDays($ledger));
    }

    public function testAPositiveAccountIsNeverCharged(): void
    {
        $ledger = self::bareLedger();
        $ledger->append(LedgerEntry::credit(self::acc(), self::aed('1000.00'), self::d(1), self::d(1), 'X'));

        self::ruleOn($ledger)->assessThrough(self::acc(), self::d(6));

        self::assertSame([], self::feeDays($ledger));
    }

    // ==================================================== criteria 2 and 6

    /**
     * ACCEPTANCE CRITERION 2, REFUSED.
     *
     * "E7 causes exactly one overdraft fee to be assessed, on Day 2."
     *
     * Read as *E7 causes one fee in total, and it falls on Day 2*, it is false here: E7 causes
     * three, on Days 2, 4 and 5. The refusal in REJECTED.md deliberately does not rest on that
     * count alone, because the count depends on the retroactive reading this build chose. Under
     * the rejected *sealed* reading the claim is still false — there the single fee lands on
     * Day 5, not Day 2. No reading of the sentence makes it true, which is what makes it safe
     * to refuse.
     */
    public function testCriterionTwoE7CausesThreeFeesNotOne(): void
    {
        $fees = self::feeDays(AssessmentLedger::throughDay(5)->ledger);

        self::assertCount(3, $fees);
        self::assertSame([2, 4, 5], $fees);
    }

    /**
     * ACCEPTANCE CRITERION 6, REFUSED — the half that lives in this rule.
     *
     * Nothing removes a fee. Day 6 reassesses every day with E9's reversal in hand and finds
     * none of them negative, so it raises nothing new — and it does not, and cannot, take back
     * the three already booked. Day 2 ends 25.00 short of where it started.
     */
    public function testCriterionSixTheFeesStandAfterTheReversal(): void
    {
        $afterE9 = AssessmentLedger::throughDay(6);

        self::assertSame([2, 4, 5], self::feeDays($afterE9->ledger), 'still three, unchanged');
        self::assertSame('225.00', $afterE9->balanceOn(2, 6));
        self::assertSame('390.00', $afterE9->balanceOn(6, 6), 'pre-interest');
    }
}
