# In-Memory Account Ledger Core

An append-only, bitemporal account ledger kernel. It replays a fixed six-day event stream across
two accounts and prints, per day: closing ledger balance, fee assessments, authorization states,
and errors.

No web layer, no persistence, no UI, no database. PHP 8.4, PHPUnit 11, no runtime dependencies.

## Running

```sh
composer install
```

### The test suite

```sh
composer test
```

**528 tests, expected green.** The one deliberately failing test is excluded from this run, so a
green result means "everything claimed to work, works" rather than "nothing is broken that we
admit to". Add `--testdox` to read the assertions as sentences:

```sh
vendor/bin/phpunit --testdox
```

### The golden test alone

```sh
composer test:golden
```

**32 tests, expected green.** Runs the whole ten-event stream through the engine and locks every
figure the brief asks about — both closing balances, both bitemporal columns for all six days,
the three fees, every event's outcome, the instalment split, and idempotent replay.

Every one of those numbers was derived by hand *before* any code existed and re-verified
independently in Python with `Decimal`/`ROUND_HALF_UP`, so it locks a verified answer rather than
whatever the implementation happened to produce. A failure here means one of two things, and both
are worth being told about: **a bug, or a documented decision silently changed.**

Equivalent, running PHPUnit directly:

```sh
vendor/bin/phpunit tests/Golden
vendor/bin/phpunit --filter AssessmentReplayTest
```

### The deliberately failing test

```sh
composer test:known-failure
```

**1 test, expected to FAIL.** That is the correct result — the brief asks for a failing test
against my own design, and this is it:

```
Tests: 1, Assertions: 1, Failures: 1.
```

It is tagged `#[Group('known-failure')]` and excluded from `composer test` by `phpunit.xml`.
What it reveals is in [The failing test](#the-failing-test) below.

Equivalent:

```sh
vendor/bin/phpunit --group known-failure
```

### The replay itself

```sh
composer replay                 # per-day table
php bin/replay --format=json    # machine-readable, what the golden test asserts against
php bin/replay --duplicates     # every event emitted twice; the figures must not move
php bin/replay --help
```

## Results

| | Closing balance | Fees | Interest |
|---|---|---|---|
| ACC-001 (AED) | **390.93** | 75.00 — Days 2, 4, 5 | 0.93 |
| ACC-002 (BHD) | **10.008** | none | 0.008 |

Auth-A approved on Day 2 and settled on Day 4. Auth-B declined on Day 5, against an available
balance of −155.00. E6 rejected — it settles an authorization that was never issued.

**Four of the eight acceptance criteria are refused: 2, 6, 7 and 8.** Criterion 5 is a near-miss
and is deliberately *accepted* — over-refusing fails the exercise as surely as missing a bad
criterion. Reasons in `REJECTED.md`.

## Reading the output

The per-day table carries **two** closing-balance columns, because in a bitemporal ledger
"Day 2's closing balance" is not one number:

| Column | Question it answers |
|---|---|
| `Closing (then)` | what the day closed at on the evening it closed |
| `Closing (final)` | what it closed at once every event had arrived |

Day 2 reads −395.00 and 225.00. Both are correct. Printing only the first hides E9; printing only
the second hides why three fees were ever charged. `AMBIGUITIES.md` §6 explains the choice.

`Fees` is what that day's close *raised* — all three of ACC-001's land on the Day 5 row, because
that is the evening E7 made them due, even though they carry value dates of Days 2, 4 and 5.

`Postings` is the ledger extract: every entry booked that day, with its value date beside its
booking day and backdated ones marked. **An event and its postings are not the same count.** E10
is one event and three entries —

```
  Day 5   CREDIT     3.334   value_date Day 5   E10.1
  Day 5   CREDIT     3.333   value_date Day 5   E10.2
  Day 5   CREDIT     3.333   value_date Day 5   E10.3
```

— because three equal instalments of BHD 10.000 do not exist at three decimal places. That split
is what refutes criterion 7. The same section makes the retroactive fee cascade legible: three
fees booked on Day 5, value-dated Days 2, 4 and 5, two of them marked backdated.

`Authorizations` lists every approve/decline with the arithmetic behind it, and `Errors` lists
every refusal. **A decline is not an error** and is not filed as one.

## The failing test

`tests/KnownFailure/FeesOutliveTheirCauseTest.php` asserts that no day ending the window with a
non-negative balance carries an overdraft fee. It fails on Days 2, 4 and 5 — **+225.00, +415.00
and +390.00, each carrying a −25.00 charge**.

That is not a bug. Two individually correct rules combine into a state neither of them intends:
fees are assessed retroactively when E7 backdates a debit into Day 2, and under append-only
nothing removes the resulting records when E9 reverses that debit. The ledger ends up holding a
charge its own final balance cannot account for.

Both available fixes are worse. Reversing the fees invents a non-negotiable rule the spec never
granted — it is exactly the reading refused in criterion 6. Sealing each day at its close discards
the value_date dimension the exercise is built on. The clean resolution is an explicit
fee-adjustment event, and the stream does not contain one.

The test's inline annotation carries all of this in full. `REJECTED.md`'s criterion 6 is the
decision it is the receipt for.

## Structure

```
src/
  Domain/          pure; no framework, no I/O
    Money/         Money, Currency, Rate, Rounding, Allocator
    Ledger/        Ledger (append-only, bitemporal balanceAsOf), LedgerEntry,
                   Account, Hold, HoldRegistry, AvailableBalance
    Event/         LedgerEvent + 5 concrete types, EventStream,
                   Decision, DecisionLog, ProcessedEvents
    Rule/          one rule per event kind, plus RuleSet, the fee and interest rules
    Service/       ReplayEngine, DailyClose, InterestSchedule, the report
  Application/     LedgerKernel (the composition root), ClosePresenter port
  Infrastructure/  the assessment's event source, console and JSON presenters
bin/replay
tests/             mirrors src/, plus Golden/ and KnownFailure/
```

## Documents

| File | Contents |
|---|---|
| `REJECTED.md` | The four criteria refused, with reasons, plus seven approaches abandoned mid-build |
| `AMBIGUITIES.md` | Every ambiguity found, how it was resolved, and what the rejected readings would have produced |
| `NUMBERS.md` | Every constant — given-by-the-spec and chosen-by-us kept apart — and why not half it |
| `WORKLOG.md` | Timestamped record of the work |
| `plan.md` | Implementation plan and hand-derived figures |

Design decisions live in those files rather than being restated here: the money representation
and rounding choices in `NUMBERS.md` Part 3, and everything reversed during the build — the
policy flags, the console framework, the DI container, the ports — in `REJECTED.md`.
