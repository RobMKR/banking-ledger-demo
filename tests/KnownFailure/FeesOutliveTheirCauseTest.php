<?php

declare(strict_types=1);

namespace Ledger\Tests\KnownFailure;

use Ledger\Application\LedgerKernel;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\EntryType;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Service\DailyLine;
use Ledger\Infrastructure\EventSource\AssessmentScenarioSource as Scenario;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * THE DELIBERATE FAILING TEST.
 *
 * Run it with `composer test:known-failure`. It is excluded from the default suite so that a
 * green `composer test` means "everything claimed to work, works" — not "nothing is broken that
 * we admit to". It is committed failing on purpose, as the brief requires.
 *
 * It is not a bug report. It is the price of a decision, made executable.
 */
#[Group('known-failure')]
#[CoversNothing]
final class FeesOutliveTheirCauseTest extends TestCase
{
    /**
     * ASSERTS: no day that ends the window with a non-negative balance carries an overdraft fee.
     *
     * FAILS ON: Day 2 (+225.00), Day 4 (+415.00) and Day 5 (+390.00) — each closing comfortably
     * in the black while carrying a -25.00 charge for having been overdrawn.
     *
     * ── WHAT IT REVEALS ──────────────────────────────────────────────────────────────────────
     *
     * Two rules that are individually correct combine into a state that cannot be defended on
     * its own terms.
     *
     *   1. Fees are assessed retroactively. E7 arrives on Day 5 carrying value_date Day 2,
     *      reopens three already-closed days, and three fees are raised. Correct: the brief
     *      defines the fee on "that day's closing ledger balance (all entries with value_date
     *      <= that day)", and after E7 those balances are negative.
     *
     *   2. The ledger is append-only. E9 reverses E7 on Day 6 and the balances return, because
     *      an appended inverse restores them. But the fee entries are records. Nothing removes
     *      a record.
     *
     * So the window ends with an account that was never, in its final accounting, overdrawn on
     * Day 2 — and that paid 25.00 for being overdrawn on Day 2. The ledger holds a charge its
     * own final state does not justify, and there is no reading of the closing balances alone
     * from which the charge can be derived.
     *
     * ── WHY IT IS NOT FIXED ──────────────────────────────────────────────────────────────────
     *
     * Both available fixes are worse than the failure.
     *
     *   Reverse the fees when the debit is reversed. This is what a bank would probably do, and
     *   it is what makes the numbers defensible — D1 to D5 would close at exactly the pre-E7
     *   figures. But no such rule is in the brief, and no fee-reversal event is in the stream.
     *   Implementing it means inventing a non-negotiable rule the spec never granted, and it is
     *   precisely the reading REJECTED.md refuses in criterion 6. Choosing it to make a test
     *   pass would be choosing the answer for the test's convenience.
     *
     *   Seal each day at its close, so a backdated entry never reopens it. Then E7 raises one
     *   fee, on Day 5, and nothing is ever charged retroactively. But that discards the
     *   value_date dimension the whole exercise is built on, and it contradicts the fee rule's
     *   own wording. It is the alternative worked through in AMBIGUITIES.md §1, with the figures
     *   it would have produced.
     *
     * ── WHAT WOULD MAKE IT PASS HONESTLY ─────────────────────────────────────────────────────
     *
     * An explicit fee-adjustment event in the stream. Under append-only that is the only clean
     * way to unwind a charge: not by deleting the record, but by appending its inverse under an
     * instruction that says so. The stream does not contain one, so the charge stands and this
     * test stays red.
     *
     * The honest summary: **this build's answer to criterion 6 costs something, and this is the
     * receipt.** See REJECTED.md (criterion 6) and AMBIGUITIES.md §3.
     */
    public function testNoDayEndingInTheBlackCarriesAnOverdraftFee(): void
    {
        $kernel = LedgerKernel::build(...Scenario::accounts());
        $report = $kernel->replay(Scenario::stream());

        $account = AccountId::of(Scenario::ACC1);
        $feeDays = array_map(
            static fn (LedgerEntry $f): int => $f->valueDate->number,
            $kernel->ledger->entriesOfType($account, EntryType::OVERDRAFT_FEE),
        );

        foreach ($report->forAccount($account) as $line) {
            if (!in_array($line->day->number, $feeDays, true)) {
                continue;
            }

            self::assertTrue(
                $line->closingRestated->isNegative(),
                $this->explain($line),
            );
        }
    }

    private function explain(DailyLine $line): string
    {
        return sprintf(
            '%s carries a 25.00 overdraft fee but ends the window at %s. The fee was correctly '
            . 'assessed when E7 made that day negative, and correctly left standing when E9 '
            . 'reversed E7 — because nothing in the stream reverses a fee, and append-only '
            . 'keeps every record. Both rules are right; the result is a charge the final '
            . 'balance cannot account for.',
            (string) $line->day,
            $line->closingRestated->format(),
        );
    }
}
