<?php

declare(strict_types=1);

namespace Ledger\Domain\Money;

use Ledger\Domain\Money\Exception\InvalidAllocation;

/**
 * Splits an amount into parts that sum back to exactly the original.
 *
 * "Three equal instalments" of BHD 10.000 is arithmetically impossible: 10.000 / 3 = 3.333…
 * and no three equal amounts at three decimal places sum to 10.000. Largest-remainder
 * allocation resolves that honestly, by making the parts as equal as the precision allows
 * and distributing the residue one minor unit at a time.
 *
 * Acceptance criterion 7 claims each instalment is 3.334, which sums to 10.002 and overpays
 * the credit. It is refused; see REJECTED.md.
 */
final class Allocator
{
    /**
     * Split into $parts amounts differing by at most one minor unit, summing to $amount.
     *
     * The residue goes to the earliest parts. Residue-last is an equally defensible
     * convention — an amortization schedule puts the balancing payment last — but it cannot
     * matter here, because every instalment of E10 carries the same value_date and therefore
     * lands on the same day. See AMBIGUITIES.md §9.
     *
     * @return list<Money>
     */
    public static function intoEqualParts(Money $amount, int $parts): array
    {
        if ($parts < 1) {
            throw InvalidAllocation::nonPositiveParts($parts);
        }

        // Allocate the magnitude and reapply the sign, so a negative amount distributes its
        // residue the same way rather than inheriting intdiv's truncation toward zero.
        $sign = $amount->minor < 0 ? -1 : 1;
        $total = abs($amount->minor);

        $base = intdiv($total, $parts);
        $residue = $total - $base * $parts;

        $allocation = [];
        for ($index = 0; $index < $parts; $index++) {
            $minor = $base + ($index < $residue ? 1 : 0);
            $allocation[] = Money::ofMinor($sign * $minor, $amount->currency);
        }

        return $allocation;
    }
}
