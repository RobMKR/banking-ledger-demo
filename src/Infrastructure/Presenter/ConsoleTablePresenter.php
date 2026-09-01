<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Presenter;

use Ledger\Application\Port\ClosePresenter;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Service\DailyLine;
use Ledger\Domain\Service\ReplayReport;

/**
 * The per-day table the brief asks for: closing ledger balance, fee assessments,
 * authorization states, and errors.
 *
 * Two balance columns, not one. "as known then" is what the day closed at when it closed;
 * "restated" is what it closed at once everything had arrived. Day 2 reads -395.00 and 225.00,
 * and printing either alone tells a lie by omission — the first hides E9, the second hides why
 * three fees were ever charged. See AMBIGUITIES.md §6.
 */
final class ConsoleTablePresenter implements ClosePresenter
{
    public function present(ReplayReport $report): string
    {
        $out = [];

        foreach ($report->closingBalances as $accountId => $balance) {
            $lines = array_values(array_filter(
                $report->lines,
                static fn (DailyLine $l): bool => $l->account->value === $accountId,
            ));

            $out[] = sprintf('=== %s ===', $accountId);
            $out[] = '';
            $out[] = sprintf('%5s  %18s  %18s  %10s   %s', 'Day', 'Closing (then)', 'Closing (final)', 'Fees', 'Events');
            $out[] = str_repeat('-', 100);

            foreach ($lines as $line) {
                $out[] = sprintf(
                    '%5d  %18s  %18s  %10s   %s',
                    $line->day->number,
                    $line->closingAsKnownThen->format(),
                    $line->closingRestated->format(),
                    $line->fees === [] ? '-' : $line->feeTotal()->format(),
                    $this->summarise($line->decisions),
                );
            }

            $out[] = '';
            $out = array_merge($out, $this->postings($lines));
            $out = array_merge($out, $this->section('Authorizations', $lines, 'authorizations'));
            $out = array_merge($out, $this->section('Errors', $lines, 'errors'));

            $out[] = sprintf('  Interest capitalized   %s', $report->interestFor(
                $lines[0]->account,
            )->format());
            $out[] = sprintf('  Closing balance        %s', $balance->format());
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * What actually reached the ledger, entry by entry.
     *
     * The table above shows an event and its outcome; this shows the postings that outcome
     * produced, which is not the same thing and occasionally not even the same count. E10 is
     * one event and three entries — 3.334 / 3.333 / 3.333, because three equal instalments of
     * 10.000 do not exist at three decimal places. That split is what refutes criterion 7, and
     * a report that only said "E10 POSTED" would be hiding the most interesting arithmetic in
     * the run.
     *
     * Value dates are printed beside booking days because they diverge, and where they do the
     * row says so: E7 is booked Day 5 and belongs to Day 2, which is the entire reason three
     * already-closed days were reassessed.
     *
     * @param list<DailyLine> $lines
     * @return list<string>
     */
    private function postings(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            foreach ($line->postings as $entry) {
                $rows[] = sprintf(
                    '  %-7s %-14s %14s   value_date %-7s %-12s %s',
                    (string) $line->day,
                    $entry->type->value,
                    $entry->amount->format(),
                    (string) $entry->valueDate,
                    $entry->isBackdated() ? '(backdated)' : '',
                    $entry->reference ?? '',
                );
            }
        }

        if ($rows === []) {
            return ['  Postings: none', ''];
        }

        return array_merge(['  Postings'], array_map(static fn (string $r): string => rtrim($r), $rows), ['']);
    }

    /**
     * @param list<DailyLine> $lines
     * @return list<string>
     */
    private function section(string $title, array $lines, string $selector): array
    {
        $rows = [];

        foreach ($lines as $line) {
            /** @var list<Decision> $decisions */
            $decisions = $line->{$selector}();
            foreach ($decisions as $decision) {
                $rows[] = sprintf('  %-7s %-4s %s', (string) $line->day, $decision->event->value, $decision->reason);
            }
        }

        if ($rows === []) {
            return ["  {$title}: none", ''];
        }

        return array_merge(["  {$title}"], $rows, ['']);
    }

    /** @param list<Decision> $decisions */
    private function summarise(array $decisions): string
    {
        if ($decisions === []) {
            return '-';
        }

        return implode(' · ', array_map(
            static fn (Decision $d): string => $d->event->value . ' ' . $d->outcome->value,
            $decisions,
        ));
    }
}
