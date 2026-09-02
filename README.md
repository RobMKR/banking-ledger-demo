# In-Memory Account Ledger Core

An append-only, bitemporal account ledger kernel. It replays a fixed six-day event stream across
two accounts and prints, per day: closing ledger balance, fee assessments, authorization states,
and errors.

No web layer, no persistence, no UI, no database. PHP 8.4, PHPUnit 11, no runtime dependencies.

## Running

**You do not need Composer, and you do not need PHP.** Everything goes through one script:

```sh
./run                  # the full suite — 543 tests, expected green
./run golden           # the golden test alone — 32 tests, expected green
./run known-failure    # the one test committed failing, deliberately
./run replay           # the per-day report
./run help             # every command
```

It picks an engine and tells you which: **your own PHP** if it is 8.4 or newer, otherwise
**Docker**. Force either with `--local` or `--docker`. Dependencies install themselves on first
run; `composer.phar` is committed here, so nothing needs installing globally.

### If you have Docker

Nothing else is required — not PHP, not Composer:

```sh
./run --docker
```

First run pulls two official images and takes a minute; after that it is instant, because
`vendor/` persists on your side of the mount. The raw command, if you would rather not trust a
script:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli php composer.phar test
```

That one works only once dependencies exist. Installing them needs a *different* image, and the
reason is worth stating because it is not obvious: **`php:8.4-cli` ships neither `ext-zip` nor an
`unzip` binary**, so Composer cannot extract a single package in it. The official `composer`
image has `unzip`, `git` and `ext-zip`:

```sh
docker run --rm -v "$PWD":/app -w /app --entrypoint composer composer:2 install
```

The code still *runs* on `php:8.4-cli`, at the version it targets. `./run` does both steps for
you. There is no Dockerfile and no image to build — two official images, cached after first use.

**If the pull fails** with a proxy or registry timeout — common on a VPN or a corporate network —
that is not this project. `./run` says so and gives you the alternatives rather than passing
Docker's raw error through. Behind a registry mirror, point it somewhere reachable:

```sh
LEDGER_PHP_IMAGE=your.mirror/php:8.4-cli LEDGER_COMPOSER_IMAGE=your.mirror/composer:2 ./run --docker
```

If you have PHP 8.4 locally, `./run --local` sidesteps the registry entirely.

### If you have PHP 8.4+

```sh
./run --local
```

or drive Composer directly — again, no global install needed:

```sh
php composer.phar install
php composer.phar test               # 543 tests, expected green
php composer.phar test:golden        # 32 tests, expected green
php composer.phar test:known-failure # expected to FAIL — see below
php bin/replay                       # per-day table
php bin/replay --format=json         # machine-readable
php bin/replay --duplicates          # every event twice; figures must not move
```

**PHP 8.4 is the version this project is built and verified against.** Every figure, every test
result and every number quoted in these documents was produced on it, and `./run --docker` pins
`php:8.4-cli` so you get the same result rather than a similar one.

Older versions will not work — the code relies on `readonly` class inheritance and would fail in
ways that look like bugs in the ledger rather than in your runtime, so `./run` refuses anything
below 8.4 instead of letting you find out the hard way. Newer versions are simply not what this
was verified on; the suite does pass on 8.5.10, but the Docker path deliberately does not use it.

### About the deliberately failing test

`./run known-failure` **exits 0 when the test fails**, because failing is the correct outcome —
the brief asks for a failing test and this is it. It exits non-zero if it ever starts passing,
which would mean a documented decision changed. Details below.

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

Day 2 reads **250.00** and **225.00**, and both are correct: it closed at 250.00 on the evening
of Day 2, and ends the window 25.00 lighter for a fee it was charged three days later. Printing
only the first hides E7 and E9 entirely; printing only the second hides what anyone actually saw
at the time. `AMBIGUITIES.md` §6 explains the choice.

A third figure exists and is deliberately not a column: **−395.00**, which is Day 2 as known at
the close of Day 5 — after E7's backdated debit and its fee, before E9 reverses them. It is the
figure the fee assessment acted on, and it is visible in the `Postings` section rather than the
table, because a third balance column would suggest the set is closed. It is not: there is one
answer per day you might ask on.

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
