<?php

declare(strict_types=1);

namespace Ledger\Tests\Infrastructure;

use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Application\LedgerKernel;
use Ledger\Domain\Service\ReplayReport;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource;
use Ledger\Infrastructure\Presenter\ConsoleTablePresenter;
use Ledger\Infrastructure\Presenter\JsonPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleTablePresenter::class)]
#[CoversClass(JsonPresenter::class)]
final class PresenterTest extends TestCase
{
    private static function report(): ReplayReport
    {
        return LedgerKernel::build(...AssessmentScenarioSource::accounts())->replay(AssessmentScenarioSource::stream());
    }

    private static function console(): string
    {
        return (new ConsoleTablePresenter())->present(self::report());
    }

    /** @return array<string, mixed> */
    private static function json(): array
    {
        return json_decode((new JsonPresenter())->present(self::report()), true, 512, JSON_THROW_ON_ERROR);
    }

    // ==================================================== the instalments

    /**
     * E10 is one event and three entries. A report saying only "E10 POSTED" would hide the most
     * interesting arithmetic in the run — the largest-remainder split that refutes criterion 7,
     * because three equal instalments of 10.000 do not exist at three decimal places.
     */
    public function testTheConsoleReportShowsTheThreeInstalmentsIndividually(): void
    {
        $output = self::console();

        self::assertStringContainsString('3.334', $output);
        self::assertStringContainsString('E10.1', $output);
        self::assertStringContainsString('E10.2', $output);
        self::assertStringContainsString('E10.3', $output);
        self::assertSame(2, substr_count($output, '3.333'), 'two instalments of 3.333');
    }

    public function testTheJsonReportCarriesTheThreeInstalmentsAsSeparatePostings(): void
    {
        $accounts = self::json()['accounts'];
        $acc2 = $accounts[1];
        $dayFive = $acc2['days'][4];

        self::assertSame(
            ['3.334', '3.333', '3.333'],
            array_column($dayFive['postings'], 'amount'),
        );
        self::assertSame(['E10.1', 'E10.2', 'E10.3'], array_column($dayFive['postings'], 'reference'));
    }

    // ==================================================== the cascade, made legible

    /**
     * All three fees are booked on Day 5 — the evening E7 made them due — while carrying value
     * dates of Days 2, 4 and 5. Printing both dates is what makes retroactive assessment
     * visible instead of leaving a reader to wonder why one day raised three charges.
     */
    public function testTheReportShowsFeesBookedOnOneDayForThreeDifferentValueDates(): void
    {
        $acc1 = self::json()['accounts'][0];
        $postings = $acc1['days'][4]['postings'];
        $fees = array_values(array_filter($postings, static fn (array $p): bool => $p['type'] === 'OVERDRAFT_FEE'));

        self::assertCount(3, $fees);
        self::assertSame([2, 4, 5], array_column($fees, 'valueDate'));
        self::assertSame([5, 5, 5], array_column($fees, 'bookedDay'));
        self::assertSame([true, true, false], array_column($fees, 'backdated'));
    }

    public function testBackdatedPostingsAreMarkedInTheConsoleReport(): void
    {
        self::assertSame(4, substr_count(self::console(), '(backdated)'),
            'E7, its two backdated fees, and E9');
    }

    // ==================================================== reconciliation

    /**
     * Every posting in the report, summed, must equal the closing balance the report states.
     *
     * A presenter is the one place a figure can be right in the ledger and wrong on the page.
     * This checks the page against itself: the entries it lists have to add up to the total it
     * prints, or one of the two is lying.
     */
    public function testThePostingsListedAddUpToTheClosingBalancePrinted(): void
    {
        foreach (self::json()['accounts'] as $account) {
            $currency = $account['account'] === AssessmentScenarioSource::ACC1 ? Currency::AED : Currency::BHD;
            $total = Money::zero($currency);

            foreach ($account['days'] as $day) {
                foreach ($day['postings'] as $posting) {
                    $total = $total->plus(Money::of($posting['amount'], $currency));
                }
            }

            self::assertSame(
                $account['closingBalance'],
                $total->format(),
                $account['account'] . ': the postings listed must sum to the balance printed',
            );
        }
    }

    // ==================================================== the rest of the page

    public function testTheHeadlineFiguresAppear(): void
    {
        $output = self::console();

        self::assertStringContainsString('390.93', $output);
        self::assertStringContainsString('10.008', $output);
        self::assertStringContainsString('75.00', $output, 'the fee total on the Day 5 row');
    }

    public function testBothClosingColumnsAreShownWhereTheyDiffer(): void
    {
        $acc1 = self::json()['accounts'][0];

        self::assertSame('250.00', $acc1['days'][1]['closingAsKnownThen']);
        self::assertSame('225.00', $acc1['days'][1]['closingRestated']);
        self::assertTrue($acc1['days'][1]['restated']);
        self::assertFalse($acc1['days'][0]['restated'], 'Day 1 was never restated');
    }

    public function testTheDeclineIsReportedAsAnAuthorizationStateAndNotAsAnError(): void
    {
        $acc1 = self::json()['accounts'][0];

        self::assertSame(['E8'], array_column($acc1['days'][4]['authorizations'], 'event'));
        self::assertSame([], $acc1['days'][4]['errors']);
        self::assertSame(['E6'], array_column($acc1['days'][3]['errors'], 'event'));
    }

    /** No JSON number anywhere a money figure lives — 390.93 does not exist in binary floating point. */
    public function testEveryMoneyFigureIsAStringNotAFloat(): void
    {
        $json = (new JsonPresenter())->present(self::report());

        self::assertStringContainsString('"closingBalance": "390.93"', $json);
        self::assertStringNotContainsString('390.93,', $json, 'never an unquoted number');
        self::assertStringContainsString('"amount": "3.334"', $json);
    }
}
