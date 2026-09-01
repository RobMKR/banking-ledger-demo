<?php

declare(strict_types=1);

namespace Ledger\Infrastructure\Presenter;

use Ledger\Application\Port\ClosePresenter;
use Ledger\Domain\Event\Decision;
use Ledger\Domain\Ledger\LedgerEntry;
use Ledger\Domain\Service\DailyLine;
use Ledger\Domain\Service\ReplayReport;

/**
 * The same report, machine-readable — what the golden test asserts against.
 *
 * Every money figure is rendered as a decimal string at the currency's own precision, never as
 * a JSON number. A float in the output would undo the entire point of storing minor units as
 * integers: 390.93 does not exist in binary floating point, and a reader parsing this back
 * would get 390.93000000000000682 and no warning.
 */
final class JsonPresenter implements ClosePresenter
{
    public function present(ReplayReport $report): string
    {
        $accounts = [];

        foreach ($report->closingBalances as $accountId => $balance) {
            $lines = array_values(array_filter(
                $report->lines,
                static fn (DailyLine $l): bool => $l->account->value === $accountId,
            ));

            $accounts[] = [
                'account' => $accountId,
                'closingBalance' => $balance->format(),
                'interestCapitalized' => $report->interestFor($lines[0]->account)->format(),
                'days' => array_map($this->day(...), $lines),
            ];
        }

        return json_encode(
            ['finalDay' => $report->finalDay->number, 'accounts' => $accounts],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function day(DailyLine $line): array
    {
        return [
            'day' => $line->day->number,
            'closingAsKnownThen' => $line->closingAsKnownThen->format(),
            'closingRestated' => $line->closingRestated->format(),
            'restated' => $line->wasRestated(),
            'fees' => array_map(
                static fn (LedgerEntry $f): string => $f->amount->negated()->format(),
                $line->fees,
            ),
            'postings' => array_map($this->posting(...), $line->postings),
            'authorizations' => array_map($this->decision(...), $line->authorizations()),
            'errors' => array_map($this->decision(...), $line->errors()),
            'events' => array_map($this->decision(...), $line->decisions),
        ];
    }

    /**
     * One ledger entry. E10 produces three of these from a single event — the largest-remainder
     * split that refutes criterion 7 — so the count here need not match the event count.
     *
     * @return array<string, string|bool|null>
     */
    private function posting(LedgerEntry $entry): array
    {
        return [
            'reference' => $entry->reference,
            'type' => $entry->type->value,
            'amount' => $entry->amount->format(),
            'valueDate' => $entry->valueDate->number,
            'bookedDay' => $entry->bookedDay->number,
            'backdated' => $entry->isBackdated(),
        ];
    }

    /** @return array<string, string> */
    private function decision(Decision $decision): array
    {
        return [
            'event' => $decision->event->value,
            'outcome' => $decision->outcome->value,
            'reason' => $decision->reason,
        ];
    }
}
