<?php

declare(strict_types=1);

namespace Ledger\Domain\Service;

use Ledger\Domain\Ledger\Account;
use Ledger\Domain\Ledger\AccountId;
use Ledger\Domain\Ledger\LedgerDay;
use Ledger\Domain\Money\Money;

/**
 * Everything the brief asks to be printed: per day, the closing ledger balance, the fee
 * assessments, the authorization states and the errors.
 *
 * Built from the ledger and the decision log after the replay rather than accumulated during
 * it, so the report is a *view* of the final state. Anything it says can be re-derived; nothing
 * in it is a running total that could drift from what the ledger holds.
 */
final readonly class ReplayReport
{
    /**
     * @param list<DailyLine>       $lines
     * @param array<string, Money>  $closingBalances  account id => final balance
     * @param array<string, Money>  $interest         account id => capitalized interest
     */
    public function __construct(
        public LedgerDay $finalDay,
        public array $lines,
        public array $closingBalances,
        public array $interest,
    ) {
    }

    /** @return list<DailyLine> */
    public function forAccount(AccountId $account): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (DailyLine $l): bool => $l->account->equals($account),
        ));
    }

    public function lineFor(AccountId $account, LedgerDay $day): ?DailyLine
    {
        foreach ($this->lines as $line) {
            if ($line->account->equals($account) && $line->day->equals($day)) {
                return $line;
            }
        }

        return null;
    }

    public function closingBalanceFor(Account|AccountId $account): Money
    {
        $id = $account instanceof Account ? $account->id : $account;

        return $this->closingBalances[$id->value];
    }

    public function interestFor(Account|AccountId $account): Money
    {
        $id = $account instanceof Account ? $account->id : $account;

        return $this->interest[$id->value];
    }
}
