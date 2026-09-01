# In-Memory Account Ledger Core

An append-only, in-memory account ledger kernel. It replays a fixed six-day event stream
across two accounts and prints, per day: closing ledger balance, fee assessments,
authorization states, and errors.

No web layer, no persistence, no UI, no database.

## Status

Under construction. Built so far:

- [x] Scaffold — Composer, PHPUnit 11
- [x] `Money`, `Currency`
- [ ] `Rate`, `Rounding`, `Allocator`
- [ ] `Ledger`, bitemporal `balanceAsOf`
- [ ] Holds and authorization
- [ ] Events, `DecisionLog`, duplicate rejection
- [ ] Settlement, reversal, overdraft fees, interest
- [ ] Replay engine, CLI, golden test

## Running

```sh
composer install
composer test               # full suite; must be green
composer test:known-failure # the one deliberate failure (see below)
```

Once the CLI exists:

```sh
php bin/replay              # per-day report
php bin/replay --format=json
```

## The deliberately failing test

One test is committed **failing**, as the brief requires. It is tagged
`@group known-failure` and excluded from the default suite, so a green `composer test` means
"everything claimed to work, works" rather than "nothing is broken that we admit to".

It asserts that no day closing with a non-negative balance carries an overdraft fee, and it
fails on D2, D4 and D5. That is not a bug — it is the cost of resolving criterion 6 the way we
did, made executable instead of merely argued. Its inline annotation explains what it reveals.
See REJECTED.md.

## Reading the output

*(To be written when the presenter exists.)*

The per-day table carries **two** closing-balance columns, because in a bitemporal ledger
"Day 2's closing balance" is not one number. Day 2 closes at −395.00 as known at the end of
Day 5, and at +225.00 once E9 arrives on Day 6. Both are correct; they answer different
questions. AMBIGUITIES.md §6 explains the choice to print both.

## Expected results

| | Closing balance | Fees | Interest |
|---|---|---|---|
| ACC-001 (AED) | **390.93** | 75.00 — D2, D4, D5 | 0.93 |
| ACC-002 (BHD) | **10.008** | none | 0.008 |

Auth-A is approved and settles on D4. Auth-B is declined. E6 is rejected — it settles an
authorization that was never issued.

## Design notes

**No floats anywhere.** `Money` holds a signed integer count of minor units, with the
currency carrying its own exponent (AED→2, BHD→3). 64-bit integers give around fourteen
orders of magnitude more headroom than this ledger needs, so GMP or BCMath would add a
runtime extension to solve a problem that does not arise. The risk that *does* arise is
PHP's silent int-to-float promotion on overflow, so every arithmetic path checks for it.

Amounts are never rounded on the way in: `Money::of('3.3333', BHD)` throws rather than
rounding, because rounding is allowed only at explicit, named boundaries.

**No money library.** `brick/money` would be the right call in production, but
largest-remainder allocation and rounding-at-named-boundaries are precisely what this
exercise assesses — delegating them would remove the evidence.

## Documents

| File | Contents |
|---|---|
| `REJECTED.md` | The four acceptance criteria refused, with reasons, plus approaches abandoned mid-build |
| `AMBIGUITIES.md` | Every ambiguity found, how it was resolved, and what the rejected readings would have produced |
| `NUMBERS.md` | Every constant, separated into given-by-the-spec and chosen-by-us |
| `WORKLOG.md` | Timestamped record of the work |
| `plan.md` | Implementation plan and verified figures |
