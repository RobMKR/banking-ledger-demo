<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Service;

use Ledger\Application\LedgerKernel;
use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\Ledger;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Money\Rate;
use Ledger\Domain\Service\InterestSchedule;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource;
use Ledger\Tests\Support\AssessmentLedger;
use Ledger\Tests\Support\AssessmentStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InterestSchedule::class)]
final class InterestScheduleTest extends TestCase
{
    private static function rate(int $bps = 4): Rate
    {
        return Rate::fromBasisPoints($bps);
    }

    private static function acc(string $id = AssessmentStream::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    private static function d(int $n): LedgerDay
    {
        return LedgerDay::of($n);
    }

    private static function scheduleOverTheWindow(): InterestSchedule
    {
        return new InterestSchedule(AssessmentLedger::throughDay(6)->ledger, self::rate());
    }

    /** @return list<string> */
    private static function formatted(array $accruals): array
    {
        return array_values(array_map(static fn (Money $m): string => $m->format(), $accruals));
    }

    // ==================================================== the window's accruals

    /**
     * 0.04% a day on each day's closing balance, read as known at the end of the window.
     *
     * The balances are 250.00 / 225.00 / 625.00 / 415.00 / 390.00 / 390.00 — after the three
     * fees and after E9's reversal. Day 4 is the one that shows the rounding: 415.00 accrues
     * 0.166, which half-up carries to 0.17 and truncation would drop to 0.16.
     */
    public function testTheAccrualForEveryDayOfTheWindow(): void
    {
        $accruals = self::scheduleOverTheWindow()->accrualsFor(self::acc(), self::d(6));

        self::assertSame(['0.10', '0.09', '0.25', '0.17', '0.16', '0.16'], self::formatted($accruals));
    }

    public function testTheCapitalizedTotalForAccountOne(): void
    {
        self::assertSame('0.93', self::scheduleOverTheWindow()->totalFor(self::acc(), self::d(6))->format());
    }

    /**
     * ACC-002 holds 10.000 BHD from Day 5, at three decimal places. Days 1 to 4 close at zero
     * and earn nothing — "positive balances only" is exact, and zero is not positive.
     */
    public function testAccountTwoAccruesOnlyOnceItHasMoney(): void
    {
        $schedule = self::scheduleOverTheWindow();
        $acc2 = self::acc(AssessmentStream::ACC2);

        self::assertSame(
            ['0.000', '0.000', '0.000', '0.000', '0.004', '0.004'],
            self::formatted($schedule->accrualsFor($acc2, self::d(6))),
        );
        self::assertSame('0.008', $schedule->totalFor($acc2, self::d(6))->format());
    }

    // ==================================================== criterion 8

    /**
     * ACCEPTANCE CRITERION 8, REFUSED.
     *
     * "If the rounded daily interest accruals do not sum to the capitalized total, the
     * remainder is discarded."
     *
     * It contradicts a stated non-negotiable — "the rounded daily accruals must sum exactly to
     * the capitalized total" — so a rule for discarding a remainder cannot coexist with it.
     * More than that, the premise never arises: the capitalized figure *is* the sum of the
     * rounded dailies. Round each day, then add. There is no separately-computed total for a
     * remainder to fall out of, so nothing is ever left over to discard.
     */
    public function testCriterionEightTheDailiesSumExactlyToTheCapitalizedTotal(): void
    {
        $schedule = self::scheduleOverTheWindow();

        // Asserted against figures derived by hand, not against the schedule's own sum. An
        // earlier version of this test compared totalFor() with the sum of accrualsFor() —
        // which is how totalFor() is implemented, so it asserted X == X and would have passed
        // against any accrual arithmetic at all, correct or not.
        self::assertSame(
            ['0.10', '0.09', '0.25', '0.17', '0.16', '0.16'],
            self::formatted($schedule->accrualsFor(self::acc(), self::d(6))),
        );
        self::assertSame('0.93', $schedule->totalFor(self::acc(), self::d(6))->format());

        // 0.10 + 0.09 + 0.25 + 0.17 + 0.16 + 0.16 = 0.93, by hand.
        self::assertSame('0.93', Money::of('0.10', Currency::AED)
            ->plus(Money::of('0.09', Currency::AED))->plus(Money::of('0.25', Currency::AED))
            ->plus(Money::of('0.17', Currency::AED))->plus(Money::of('0.16', Currency::AED))
            ->plus(Money::of('0.16', Currency::AED))->format());
    }

    /**
     * The same property at a rate chosen so every single day rounds, so the claim cannot be
     * passing on figures that happen to be exact. At 7 bps the raw dailies are
     * 0.175 / 0.1575 / 0.4375 / 0.2905 / 0.273 / 0.273 — not one is clean at two places.
     *
     * Expected values written out longhand from those raw figures under HALF_UP, so the test
     * has an opinion of its own rather than echoing the implementation.
     */
    public function testTheSumPropertyHoldsAtARateWhereEveryDayRounds(): void
    {
        $schedule = new InterestSchedule(AssessmentLedger::throughDay(6)->ledger, self::rate(7));

        //          0.175 -> 0.18   0.1575 -> 0.16   0.4375 -> 0.44
        //          0.2905 -> 0.29  0.273 -> 0.27    0.273 -> 0.27      sum: 1.61
        self::assertSame(
            ['0.18', '0.16', '0.44', '0.29', '0.27', '0.27'],
            self::formatted($schedule->accrualsFor(self::acc(), self::d(6))),
        );
        self::assertSame('1.61', $schedule->totalFor(self::acc(), self::d(6))->format());
    }

    /**
     * The other half of criterion 8's refutation, and the half that was missing: whatever the
     * schedule computes has to be what the ledger ends up holding. A total that is internally
     * consistent but disagrees with the posted credit breaks the non-negotiable exactly where
     * it matters — on the artefact someone reads.
     */
    public function testTheCapitalizedCreditIsTheSumOfTheDailies(): void
    {
        $kernel = LedgerKernel::build(...AssessmentScenarioSource::accounts());
        $report = $kernel->replay(AssessmentScenarioSource::stream());

        foreach (AssessmentScenarioSource::accounts() as $account) {
            $posted = $kernel->ledger->entriesOfType($account->id, EntryType::INTEREST);

            self::assertCount(1, $posted, $account->id->value . ': one credit, not six');
            self::assertSame(
                $posted[0]->amount->format(),
                $report->interestFor($account->id)->format(),
                $account->id->value . ': the posted credit and the reported total must agree',
            );
        }
    }

    // ==================================================== "positive balances only"

    public function testANegativeDayAccruesNothingRatherThanChargingInterest(): void
    {
        $ledger = new Ledger(Account::emptyIn(AssessmentStream::ACC1, Currency::AED));
        $ledger->append(LedgerEntry::debit(
            self::acc(), Money::of('500.00', Currency::AED), self::d(1), self::d(1), 'X',
        ));

        $schedule = new InterestSchedule($ledger, self::rate());

        self::assertSame('0.00', $schedule->accrualsFor(self::acc(), self::d(1))[1]->format());
        self::assertSame('0.00', $schedule->totalFor(self::acc(), self::d(1))->format());
    }

    public function testABalanceTooSmallToEarnAMinorUnitAccruesZero(): void
    {
        $ledger = new Ledger(Account::emptyIn(AssessmentStream::ACC1, Currency::AED));
        // 12.49 x 0.04% = 0.004996, which half-up at two places is 0.00.
        $ledger->append(LedgerEntry::credit(
            self::acc(), Money::of('12.49', Currency::AED), self::d(1), self::d(1), 'X',
        ));

        self::assertSame(
            '0.00',
            (new InterestSchedule($ledger, self::rate()))->totalFor(self::acc(), self::d(1))->format(),
        );
    }

    /**
     * Accruals are restated against final knowledge (AMBIGUITIES.md §4). Day 2 earns on the
     * 225.00 it ends the window at, not on the -370.00 it stood at when Day 5 closed — E7's
     * debit and E9's reversal have both landed before a single accrual is computed.
     *
     * This is the decision of the four held with lower confidence, and §4 says so plainly: the
     * account genuinely was without that money from Day 5 until Day 6, was declined an
     * authorization over it, and was charged three fees on it. Paying interest as though E7
     * never happened is defensible, not obvious.
     */
    public function testAccrualsAreRestatedAgainstWhatIsKnownAtTheEnd(): void
    {
        $accruals = self::scheduleOverTheWindow()->accrualsFor(self::acc(), self::d(6));

        self::assertSame('0.09', $accruals[2]->format(), '0.04% of the 225.00 Day 2 ends at');
        self::assertSame(
            '-395.00',
            AssessmentLedger::throughDay(5)->balanceOn(2, 5),
            'and Day 2 really was deeply negative mid-window — the as-known reading would accrue 0.00 here',
        );
    }
}
