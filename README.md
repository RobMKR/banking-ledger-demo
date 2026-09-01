# In-Memory Account Ledger Core

An append-only, in-memory account ledger kernel. It replays a fixed six-day event stream
across two accounts and prints, per day: closing ledger balance, fee assessments,
authorization states, and errors.

No web layer, no persistence, no UI, no database.

## Status

Under construction. Built so far:

- [x] Scaffold — Composer, PHPUnit 11
- [x] `Money`, `Currency`
- [x] `Rate`, `Rounding`, `Allocator`
- [x] `Ledger`, bitemporal `balanceAsOf`
- [x] Holds, available balance, authorization rule
- [x] Events, `DecisionLog`, outcomes
- [x] Duplicate event rejection
- [x] Settlement, orphan rejection
- [x] Reversal, overdraft fees, interest
- [x] Replay engine, CLI, golden test
- [ ] The deliberate failing test, docs finalisation

## Running

```sh
composer install
composer test               # full suite; must be green
composer test:known-failure # the one deliberate failure (see below)
```

```sh
php bin/replay                  # per-day table
php bin/replay --format=json    # machine-readable, what the golden test asserts against
php bin/replay --duplicates     # every event emitted twice; the figures must not move
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

The per-day table carries **two** closing-balance columns, because in a bitemporal ledger
"Day 2's closing balance" is not one number:

| Column | Question it answers |
|---|---|
| `Closing (then)` | what the day closed at on the evening it closed |
| `Closing (final)` | what it closed at once every event had arrived |

Day 2 reads −395.00 and 225.00. Both are correct. Printing only the first hides E9; printing
only the second hides why three fees were ever charged. AMBIGUITIES.md §6 explains the choice.

`Fees` is what that day's close *raised* — all three of ACC-001's land on the Day 5 row, because
that is the evening E7 made them due, even though they carry value dates of Day 2, 4 and 5.

`Postings` is the ledger extract: every entry booked that day, with its value date beside its
booking day and backdated ones marked. **An event and its postings are not the same count.** E10
is one event and three entries —

```
  Day 5   CREDIT     3.334   value_date Day 5   E10.1
  Day 5   CREDIT     3.333   value_date Day 5   E10.2
  Day 5   CREDIT     3.333   value_date Day 5   E10.3
```

— because three equal instalments of BHD 10.000 do not exist at three decimal places. That split
is what refutes criterion 7, so the report shows it rather than saying "E10 POSTED" and leaving
the most interesting arithmetic in the run invisible. The same section is what makes the
retroactive fee cascade legible: three fees booked on Day 5, value-dated Days 2, 4 and 5.

Below that, `Authorizations` lists every approve/decline with the arithmetic behind it, and
`Errors` lists every refusal. A decline is not an error and is not filed as one.

**No console framework.** `symfony/console` was planned and dropped: what it would buy here is
argument parsing for one optional flag, against a runtime dependency in a project whose whole
argument is that the arithmetic is the deliverable. `getopt()` is four lines. See REJECTED.md.

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

**Constructor injection, one composition root, no container.** Every class takes its
collaborators and builds none of its own; `Application\LedgerKernel` is the single place that
knows how the graph fits together. A container library earns its place when wiring is large,
conditional, or configured at runtime — this is fifteen objects in one arrangement, and
reflection-driven autowiring would replace a list you can read with a magic you cannot.

The kernel exposes `with(accounts, overdraftFee, interestRate)` alongside `forAssessment()`.
That is **not** a policy switch: the four resolved ambiguities stay hardcoded and unreachable,
which is the whole reason there are no flags. It exists so NUMBERS.md's "why that value and not
half it" can be executed rather than asserted — halving the fee provably changes nothing, and
raising it past 30.00 provably raises a fourth.

## Documents

| File | Contents |
|---|---|
| `REJECTED.md` | The four acceptance criteria refused, with reasons, plus approaches abandoned mid-build |
| `AMBIGUITIES.md` | Every ambiguity found, how it was resolved, and what the rejected readings would have produced |
| `NUMBERS.md` | Every constant, separated into given-by-the-spec and chosen-by-us |
| `WORKLOG.md` | Timestamped record of the work |
| `plan.md` | Implementation plan and verified figures |
