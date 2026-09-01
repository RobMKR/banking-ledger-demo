<?php

declare(strict_types=1);

namespace Ledger\Tests\Application;

use Ledger\Application\LedgerKernel;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Money\Rate;
use Ledger\Domain\Money\Rounding;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource as Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerKernel::class)]
final class LedgerKernelTest extends TestCase
{
    private static function acc(string $id = Scenario::ACC1): AccountId
    {
        return AccountId::of($id);
    }

    /** @return list<int> the value dates the overdraft fees were booked against */
    private static function feeDays(LedgerKernel $kernel): array
    {
        return array_map(
            static fn (LedgerEntry $f): int => $f->valueDate->number,
            $kernel->ledger->entriesOfType(self::acc(), EntryType::OVERDRAFT_FEE),
        );
    }

    // ==================================================== the wiring

    public function testTheAssessmentConfigurationProducesTheShippedFigures(): void
    {
        $kernel = LedgerKernel::build(...Scenario::accounts());

        $report = $kernel->replay(Scenario::stream());

        self::assertSame('390.93', $report->closingBalanceFor(self::acc())->format());
        self::assertSame('10.008', $report->closingBalanceFor(self::acc(Scenario::ACC2))->format());
    }

    /**
     * The kernel hands back the same objects the engine is holding, not copies. A test that
     * inspects $kernel->log after a replay has to be looking at the log the replay wrote to,
     * or it would be asserting against an empty stand-in and passing for the wrong reason.
     */
    public function testTheKernelExposesTheVeryObjectsTheEngineUses(): void
    {
        $kernel = LedgerKernel::build(...Scenario::accounts());

        $kernel->replay(Scenario::stream());

        self::assertSame(10, $kernel->log->count());
        self::assertSame(10, $kernel->processed->count());
        self::assertGreaterThan(0, $kernel->ledger->count());
        self::assertTrue($kernel->holds->has(\Ledger\Domain\Ledger\AuthorizationId::of('Auth-A')));
    }

    public function testEachKernelIsAFreshGraph(): void
    {
        $first = LedgerKernel::build(...Scenario::accounts());
        $first->replay(Scenario::stream());

        $second = LedgerKernel::build(...Scenario::accounts());

        self::assertSame(0, $second->log->count(), 'no state leaks between kernels');
        self::assertSame(0, $second->ledger->count());
        self::assertSame(
            '390.93',
            $second->replay(Scenario::stream())->closingBalanceFor(self::acc())->format(),
        );
    }

    // ==================================================== NUMBERS.md, made executable

    /**
     * "Fee 25.00: load-bearing within 5.00. At close of D5, D3 closes at exactly +5.00. A fee of
     * 12.50 leaves D3 at +17.50 (no change to the cascade); a fee above 30.00 drives D3 negative
     * and triggers a *fourth* fee. The chosen value sits near a cliff."
     *
     * That claim was prose until the composition root made the constant injectable. It is the
     * whole point of NUMBERS.md's "why that value and not half it", so it should be run, not
     * asserted — halving the fee genuinely changes nothing about the cascade, and that is a more
     * interesting fact than it sounds.
     *
     * @return iterable<string, array{string, list<int>}>
     */
    public static function feeSensitivity(): iterable
    {
        yield 'half the fee — cascade unchanged' => ['12.50', [2, 4, 5]];
        yield 'the shipped fee'                  => ['25.00', [2, 4, 5]];
        yield 'just under the cliff'             => ['30.00', [2, 4, 5]];
        yield 'over the cliff — Day 3 falls too' => ['35.00', [2, 3, 4, 5]];
    }

    #[DataProvider('feeSensitivity')]
    public function testTheFeeSitsJustUnderACliff(string $fee, array $expectedFeeDays): void
    {
        $kernel = LedgerKernel::with(
            Scenario::accounts(),
            Money::of($fee, Currency::AED),
            Rate::fromBasisPoints(4),
        );
        $kernel->replay(Scenario::stream());

        self::assertSame($expectedFeeDays, self::feeDays($kernel));
    }

    /**
     * The cliff edge itself, read where NUMBERS.md reads it: Day 3 *as known at the close of
     * Day 5*, which is neither report column — the report shows what Day 3 closed at on Day 3
     * (650.00, before E7 existed) and what it ended the window at. The figure that decides
     * whether a fourth fee is raised is the mid-window one, and only balanceAsOf gives it.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function dayThreeAtCloseOfDayFive(): iterable
    {
        yield 'half the fee'  => ['12.50', '17.50', 3];
        yield 'shipped'       => ['25.00', '5.00', 3];
        yield 'exactly at 30' => ['30.00', '0.00', 3];
        yield 'past the edge' => ['35.00', '-5.00', 4];
    }

    #[DataProvider('dayThreeAtCloseOfDayFive')]
    public function testDayThreeIsWhatDecidesWhetherAFourthFeeIsRaised(
        string $fee,
        string $dayThreeAtDayFive,
        int $feeCount,
    ): void {
        $kernel = LedgerKernel::with(
            Scenario::accounts(),
            Money::of($fee, Currency::AED),
            Rate::fromBasisPoints(4),
        );
        $kernel->replay(Scenario::stream());

        // Day 3 as the ledger knew it at the close of Day 5 — but read *before* its own fee, so
        // the figure is the one the assessment was deciding on.
        $withoutOwnFee = $kernel->ledger->balanceAsOf(self::acc(), LedgerDay::of(3), LedgerDay::of(5));
        if ($feeCount === 4) {
            $withoutOwnFee = $withoutOwnFee->plus(Money::of($fee, Currency::AED));
        }

        self::assertSame($dayThreeAtDayFive, $withoutOwnFee->format());
        self::assertCount($feeCount, self::feeDays($kernel));
    }

    /**
     * "HALF_UP: no accrual in this dataset lands on an exact half ... the result is invariant
     * across the half-tie modes."
     *
     * True at 0.04%, and NUMBERS.md's answer to "why not half it" turns on the fact that it
     * stops being true at 0.02%: 225.00 accrues exactly 0.045 and 625.00 exactly 0.125, both
     * genuine ties. Halving the rate does not merely shrink the figures — it moves the rounding
     * mode from inert to decisive, which is a far better reason to keep 0.04% than "the numbers
     * are bigger".
     */
    public function testHalvingTheRateTurnsRoundingFromInertIntoDecisive(): void
    {
        $shipped = LedgerKernel::with(Scenario::accounts(), Money::of('25.00', Currency::AED), Rate::fromBasisPoints(4));
        $halved = LedgerKernel::with(Scenario::accounts(), Money::of('25.00', Currency::AED), Rate::fromBasisPoints(2));

        $atFour = $shipped->replay(Scenario::stream());
        $atTwo = $halved->replay(Scenario::stream());

        // First: the rate reaches the arithmetic at all. Without this the rest of the test
        // would pass against a kernel that quietly ignored its own argument.
        self::assertSame('0.93', $atFour->interestFor(self::acc())->format());
        self::assertSame('0.47', $atTwo->interestFor(self::acc())->format());
        self::assertSame('390.93', $atFour->closingBalanceFor(self::acc())->format());
        self::assertSame('390.47', $atTwo->closingBalanceFor(self::acc())->format());

        // Then the point: halving does not merely shrink the accruals.
        // The balances they are computed on are identical in both runs.
        $balances = [25000, 22500, 62500, 41500, 39000, 39000];

        $tiesAtFour = array_filter($balances, static fn (int $b): bool => Rounding::landsOnATie($b * 4, 10_000));
        $tiesAtTwo = array_filter($balances, static fn (int $b): bool => Rounding::landsOnATie($b * 2, 10_000));

        self::assertSame([], $tiesAtFour, 'at 0.04% no accrual lands on a half — the mode is inert');
        self::assertSame(
            [22500, 62500],
            array_values($tiesAtTwo),
            'at 0.02% two of them do: 0.045 and 0.125',
        );

        // And the proof it matters: half of 0.93 is 0.465, but the halved run yields 0.47.
        // The two ties break upward under HALF_UP, so the rounding mode is doing real work at
        // 0.02% and none at 0.04%.
        self::assertNotSame('0.465', $atTwo->interestFor(self::acc())->format());
    }

    public function testTheTwoConstantsAreTheOnlyThingWithLetsYouChange(): void
    {
        $reflection = new \ReflectionMethod(LedgerKernel::class, 'with');

        self::assertSame(
            ['accounts', 'overdraftFee', 'interestRate'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $reflection->getParameters()),
            'no policy flags: the four resolved ambiguities stay hardcoded and unreachable',
        );
    }
}
