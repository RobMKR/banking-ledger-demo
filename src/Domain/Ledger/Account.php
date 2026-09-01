<?php

declare(strict_types=1);

namespace Ledger\Domain\Ledger;

use Ledger\Domain\Money\Currency;
use Ledger\Domain\Money\Money;
use Ledger\Domain\Ledger\Exception\AccountCurrencyMismatch;

/**
 * An account: an identity, a currency, and an opening balance.
 *
 * The opening balance is held as a field rather than a synthetic entry, so the ledger never
 * contains a record that no event produced. Both accounts in the brief open at zero, but the
 * arithmetic is written to work for any opening figure.
 */
final readonly class Account
{
    private function __construct(
        public AccountId $id,
        public Currency $currency,
        public Money $openingBalance,
    ) {
    }

    public static function opening(string $id, Money $openingBalance): self
    {
        return new self(AccountId::of($id), $openingBalance->currency, $openingBalance);
    }

    public static function emptyIn(string $id, Currency $currency): self
    {
        return self::opening($id, Money::zero($currency));
    }

    /** @throws AccountCurrencyMismatch */
    public function assertHolds(Money $amount): void
    {
        if ($amount->currency !== $this->currency) {
            throw AccountCurrencyMismatch::between($this->id, $this->currency, $amount->currency);
        }
    }
}
