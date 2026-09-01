# In-Memory Account Ledger Core — Implementation Plan

> **Status.** REJECTED.md, AMBIGUITIES.md, NUMBERS.md, README.md and WORKLOG.md are written
> and authoritative; this plan defers to them where they overlap. Commit steps 1–5 are built
> and green (425 tests) — money, rounding, allocation, the bitemporal ledger, holds, the
> authorization rule, the event/decision layer, duplicate rejection and settlement. Still to
> build: steps 9–16 — reversal, fees, interest, replay engine, CLI.
>
> The NUMBERS.md draft that used to live in this file has been superseded by the real file;
> its figures were re-derived rather than copied, and two were corrected in the process.

## Context

A tech assessment. Build an in-memory, append-only account ledger kernel that replays a
fixed 6-day event stream across two accounts (ACC-001 AED, ACC-002 BHD) and prints, per
day: closing ledger balance, fee assessments, authorization states, and errors. No web
layer, no persistence, no UI, no database.

The assessment embeds a trap: eight numbered "acceptance criteria" are supplied, of which
exactly **four are wrong — 2, 6, 7, 8**. Part of the deliverable is identifying and refusing
those with reasons. Criterion 5 is a near-miss engineered to bait an over-refusal, and is
accepted — over-refusing is as much a failure as under-refusing.

**The problem is bitemporal, and everything else follows from that.** Every entry carries
two independent dates: `value_date` (which day the money belongs to) and `booked_day`
(which day the ledger learned of it). E7 (booked D5, value_date D2) and E9 (booked D6,
value_date D2) exist solely to force this. "The closing balance for Day 2" is not a value;
it is a function of two arguments. `balanceAsOf(account, valueDate, knownAsOf)` is the
core primitive — correct there, everything downstream is achievable; wrong there, nothing
downstream can be made correct.

Four genuine ambiguities remain unresolvable from the spec. **Each is resolved to a single
chosen reading and hardcoded — there are no policy flags.** The rejected readings, and the
exact figures they would have produced, are documented in AMBIGUITIES.md. Shipping one code
path keeps the kernel honest: a configurable engine can pass every combination and still
demonstrate no position on any of them, whereas a single path plus a worked counterfactual
shows both the decision and the understanding behind it.

## Stack

PHP 8.4 + Composer. `symfony/console` for the replay CLI. PHPUnit 11. No other runtime
deps. Framework-free domain core — the spec bans web/DB/UI, which removes everything
full-skeleton Symfony would provide; using console-only is the defensible read and is
called out as a deliberate choice in the README.

**No floats anywhere.** Money is an integer minor-unit value object carrying its own
currency exponent (AED→2, BHD→3). The interest rate is the rational 4/10000, never
`0.0004`. Rounding happens only at explicit, named boundaries.

## Verified figures (hand-derived; these become golden tests)

ACC-001 closing balances, as known at each daily close:

| Close | D1 | D2 | D3 | D4 | D5 | D6 |
|---|---|---|---|---|---|---|
| end D4 | 250.00 | 250.00 | 650.00 | 465.00 | — | — |
| end D5 | 250.00 | −395.00 | 5.00 | −205.00 | −230.00 | — |
| end D6 | 250.00 | 225.00 | 625.00 | 415.00 | 390.00 | 390.93 |

- **Three overdraft fees** (D2, D4, D5) = AED 75.00, under the chosen retroactive-assessment
  reading. E7's backdated −620.00 drags D2 negative and the cascade carries through D4 and
  D5. D3 survives at +5.00. Under the rejected *sealed* reading the count would be **one fee,
  on D5** — the single most load-bearing decision in the build, resolved in Policy decisions
  and worked through in AMBIGUITIES.md rather than left as a silent assumption.
- **Auth-A approved** at D2 (available 250 − 200 = 50 ≥ 0). Settles D4 for 185.00,
  releasing the 200.00 hold.
- **Auth-B declined.** At E8 available is −155.00 before the hold — Auth-A's 200.00 hold was
  released at settlement (E5), so no hold is active. A 90.00 hold cannot leave that ≥ 0. The
  decline is robust to every resolved ambiguity: −335.00 had orphans been force-posted (still
  < 0), and unaffected by the assessment reading, since fees are assessed at daily close,
  after E8 is already decided.
- **Interest** (default policy): 0.10 + 0.09 + 0.25 + 0.17 + 0.16 + 0.16 = AED 0.93,
  capitalized as one credit at value_date D6 → final 390.93.
- **ACC-002**: instalments 3.334 / 3.333 / 3.333 = 10.000. Interest 0.004 + 0.004 =
  BHD 0.008 → final 10.008. Never negative, no fees.

## Criteria verdicts

Numbered 1–8 exactly as in `assessment_plain.md`, where the "some of the following are
wrong" instruction sits unnumbered above the list. Verified 1:1 against that file.

| # | Claim | Verdict | Why |
|---|---|---|---|
| 1 | Day 2 = −370.00 at end of D5, pre-fee | ✅ **Accept** | 1200 − 950 − 620 = −370.00, exactly |
| 2 | E7 causes exactly one fee, **on Day 2** | ❌ **REFUSE** | False under *both* assessment policies |
| 3 | Auth-A's Day 4 settlement accepted | ✅ **Accept** | 185.00 sits inside the live 200.00 hold |
| 4 | Orphan settlement rejected, funds stay | ✅ **Accept** | Honored as the default; caveat → AMBIGUITIES.md |
| 5 | If Auth-B approved, hold hits available not ledger | ✅ **Accept** | Sound hold semantics; vacuous — Auth-B declines |
| 6 | After E9, balances and fees return to pre-E7 | ❌ **REFUSE** | Fee records are permanent; D2 closes +225.00 |
| 7 | Instalments each BHD 3.334 | ❌ **REFUSE** | 3 × 3.334 = 10.002 ≠ 10.000 |
| 8 | Interest remainder discarded | ❌ **REFUSE** | Contradicts a stated non-negotiable rule |

**REJECTED.md therefore carries four refusals — 2, 6, 7, 8** — plus one "considered and
*not* refused" entry for criterion 5, so its acceptance reads as a decision, not an
oversight.

Four of these need their reasoning stated precisely, because the obvious reason is the
wrong one:

**Criterion 2 — the sentence is ambiguous, and the refusal has to survive both readings.**
"E7 causes exactly one overdraft fee to be assessed, on Day 2" can mean (a) *E7 causes one fee
in total, and it falls on D2*, or (b) *D2 receives exactly one fee*. We refuse under (a): the
count attaches to what E7 causes, and it is the only reading that states something testable,
since "once per day per account" already makes two fees on one day impossible.

Under (a) the claim fails either way — three fees (D2, D4, D5) on the retroactive reading, one
fee on **D5** under sealed. Under (b) it is true in the shipped configuration but empty (the
rule guarantees it), and false under sealed, where D2 is never revisited and gets none.

REJECTED.md refuses it on that basis, deliberately *not* on "three fees" — which is true only
under the reading we chose and would collapse if the assessor intended the other.

**Criterion 5 — accepted, and REJECTED.md says why it is not refused.** "*If* Auth-B is
approved, its hold reduces available balance but not ledger balance" is a conditional. Its
consequent is a correct statement of hold semantics that this design implements and tests;
its antecedent simply does not obtain, because Auth-B declines. A conditional is not
falsified by a false antecedent, so refusing it would be a logic error dressed up as
rigour. REJECTED.md is scoped to refusals only, so the reasoning lives in AMBIGUITIES §12,
which also records that "Auth-B" may be a slip for "Auth-A" — the stream's note that *"Auth-B
is never settled inside the window"* only carries information if its author expected the
authorization to be approved, since a declined one cannot settle. Under either reading the
rule asserted is correct and we implement it, so the acceptance stands.

**Criterion 6 — refuse on the records, not on "append-only forbids it".** Append-only
forbids *mutating stored records*; it does not forbid a balance returning to a prior value,
since appended reversals can restore one. An auto-reversing reading does exactly that:
D1–D5 would close at 250 / 250 / 650 / 465 / 465, precisely the pre-E7 figures. So the
refusal cannot rest on append-only alone. It rests on two narrower facts: (a) E9 reverses
E7 and nothing else — no fee-reversal event exists in the stream, and the non-negotiables
grant no auto-reversal rule, so the three fees stand and D2 closes at +225.00; and (b) the
fee *entries* are permanent under append-only on any reading, so "fees return to their
pre-E7 values" — read as those charges ceasing to exist — is unachievable outright.

**Criteria 7 and 8 — the two unconditional refusals.** 7 dies on arithmetic:
3 × 3.334 = 10.002, overpaying the 10.000 credit by 0.002 and breaking the instalment total.
The allocation is 3.334 / 3.333 / 3.333 by largest remainder. 8 dies on direct
contradiction: the non-negotiable rules already require that "the rounded daily accruals
must sum exactly to the capitalized total", so a rule discarding the remainder cannot
coexist with them. Neither refusal depends on any resolved ambiguity — both hold on every
reading, which makes them the two safest entries in REJECTED.md.

## Policy decisions (resolved, not configurable)

Each is hardcoded. Each gets a full AMBIGUITIES.md entry stating the alternative, the
argument for it, and the figure it would have produced.

1. **Retroactive assessment — a backdated entry reopens already-closed days.** The rule
   defines the trigger as "that day's closing ledger balance (all entries with value_date ≤
   that day)", and `value_date` is precisely the dimension E7 moves, so D2's balance genuinely
   changes when E7 arrives. Rejected alternative: *sealed*, where a day's fee decision is
   final once taken and only D5 is assessed. This is the highest-blast-radius decision in the
   build — 75.00 vs 25.00 in fees — and criterion 2's refusal is deliberately constructed to
   survive either way.
2. **Orphan settlements are rejected; funds stay.** Honors criterion 4. Rejected
   alternative: *force-post*, which models real card-network behaviour where the money has
   already moved and rejection creates a reconciliation break rather than preventing a loss.
   The brief's criterion outranks industry practice here, and E6's rejection is recorded in
   the DecisionLog rather than silently dropped.
3. **Assessed fees stand; they are never auto-reversed.** Each fee was correct against the
   ledger state when assessed, and unwinding one needs an explicit adjustment event the
   stream does not contain. Rejected alternative: *auto-reverse*, which appends compensating
   entries once E9 lands. Inventing that rule would be inventing a rule the spec never gave —
   and it is exactly what the deliberate failing test exposes as the cost of this choice.
4. **Interest accruals are restated against final knowledge.** Accruals are not booked
   entries until the D6 capitalization, so restating them mutates nothing and violates no
   append-only rule; capitalizing at D6 means every day's balance is best-known at that
   point. Rejected alternative: *as-known*, accruing against each day's knowledge at its own
   close, which is closer to how a real bank accrues nightly but books a D5 accrual of 0.00
   that the final ledger contradicts.

   **This is the one decision of the four we hold with lower confidence, and AMBIGUITIES §4
   says so.** The account genuinely was without the money from D5 until E9 landed on D6 — it
   was declined an authorization over it and charged three fees on it — so paying interest as
   though E7 never happened is not obviously right. It also makes the configuration
   asymmetric: E7's consequences *stand* for fees (§3) and are *unwound* for interest (§4).
   The mechanical split is principled (fees are booked records, accruals are not entries until
   D6), but that is an accounting argument, not an economic one. Recorded as unresolved.

**No fixpoint iteration is needed, and the scaffolding for one is dropped.** A fee books
with `value_date` equal to the day whose balance was negative, so it can only ever affect
days ≥ that day — never an earlier one. A single ascending pass D1→D6 therefore *is* the
fixpoint, and "once per day per account" bounds the outcome at one fee per day (≤ 6 total).
This is asserted by a test proving a second pass is a no-op, rather than defended at
runtime by an iteration cap guarding against a loop that cannot occur. The earlier
"monotone decreasing / convergence guard / defensive assert" design is recorded in
REJECTED.md as an approach abandoned mid-build, with this proof as the reason.

Two sub-ambiguities to document:

- **A fee's own value_date.** "Booked with value_date equal to the day assessed" is
  ambiguous once assessment is retroactive: the day whose balance was negative (D2), or the
  day the assessment ran (D5)? Resolved to the former — it keeps the fee in the period that
  caused it and is what criterion 2's own phrasing ("on Day 2") assumes. The latter would
  pile D2's and D4's fees onto D5 and change the cascade; noted, not silently discarded.
- **A fee reversal's value_date** (original fee's day vs. day of reversal). Default to the
  original day; note the divergence.

**Counterfactuals for AMBIGUITIES.md.** Only the top row is executable; the rest are what
the rejected readings *would* have produced, each changing exactly one decision:

| Changed decision | Fees | Interest | ACC-001 final | |
|---|---|---|---|---|
| *(none — the shipped configuration)* | 75.00 | 0.93 | **390.93** | ✅ built |
| interest → as-known | 75.00 | 0.81 | 390.81 | derived |
| fees → auto-reverse | 0.00 | 1.03 | 466.03 | derived |
| orphan → force-post | 75.00 | 0.69 | 210.69 | derived |
| assessment → sealed | 25.00 | 1.01 | 441.01 | derived |

The four derived rows are **not reachable from the binary** — that is the point of removing
the flags. AMBIGUITIES.md therefore shows the day-by-day arithmetic for each, so a reader
can check them by hand without running anything, and the plan does not claim a test result
it never produced. The `sealed` row is the one that matters most: it is the figure an
assessor expecting "exactly one fee" would look for, and it still does not vindicate
criterion 2, because the fee lands on D5.

## Beyond the brief — duplicate event rejection

The stream contains no duplicates, so nothing below is required by the brief. It is a
deliberate addition, flagged as such so it is not mistaken for a requirement read into the
spec — the same discipline REJECTED.md applies in the other direction.

**Why it earns its place.** The ledger is append-only: no entry is ever mutated or deleted. A
double-post is therefore *unrecoverable* — it cannot be deleted, only offset by a compensating
reversal, which is exactly the mess criterion 6 is refused over. Meanwhile every real event
stream is at-least-once; retries and operator re-runs are routine. Without a guard the engine
would post E7 twice and produce a silently wrong ledger with no error anywhere.

**The rule.** An event whose ID has already been seen is rejected and recorded in the
`DecisionLog` as `REJECTED_DUPLICATE_EVENT_ID`. Nothing is posted. The check runs first — before
any rule, before validation, before the ledger or hold registry are touched.

Kept deliberately minimal. Two richer variants were considered and dropped as scope creep:

- *Payload hashing*, to separate a benign retry from a same-ID-different-payload integrity
  breach. The stream has neither case, and nothing here would act on the distinction.
- *Authorization-ID dedup*, so a second `AUTHORIZATION` claiming `Auth-A` could not
  double-reserve. No such event exists, and the hold registry is already keyed by auth ID.

**Stated limitations**, since a guard that overstates its coverage is worse than none:

1. IDs only — a same-ID-different-payload event is absorbed as a plain duplicate, and the
   upstream integrity problem is never surfaced.
2. Event IDs only — a second authorization claiming a live auth ID would still double-reserve.
3. In-memory, so the registry is scoped to a single replay. The brief bans persistence, so there
   is nowhere to keep it; a real deployment needs a durable idempotency key.

**Documented here and in the README, not in AMBIGUITIES.md.** This is a design decision, not a
spec ambiguity — the brief is not unclear about duplicates, it simply never raises them. Filing
it there would repeat the mistake already corrected when §12 (reversal guards) and §13 ("zero is
not positive") were cut as padding.

## Structure

```
src/
  Domain/                      # pure; zero dependencies
    Money/       Currency, Money, Rate, Rounding, Allocator (largest-remainder)
    Ledger/      AccountId, LedgerDay, EntryType, LedgerEntry,
                 Ledger (append-only; balanceAsOf), Hold, HoldRegistry
    Event/       LedgerEvent + 6 concrete types, EventId, ProcessedEvents
                 (seen-ID registry), EventOutcome, DecisionLog
    Rule/        AuthorizationRule, OverdraftFeeRule, InterestAccrualRule,
                 SettlementRule, ReversalRule
                 (one implementation each — no strategy interfaces, no
                  alternates; named so each rule is greppable from its
                  AMBIGUITIES.md entry)
    Service/     DailyClose (single ascending assessment pass + accrual
                 schedule), ReplayEngine, InterestSchedule
  Application/
    ReplayScenario.php         # use case, depends only on ports
    Port/        EventSourcePort, ClosePresenterPort   # the only real boundaries
    Dto/         DailyReport  (carries as-known AND restated columns)
  Infrastructure/
    EventSource/ AssessmentScenarioSource (+ withDuplicates() variant)
    Presenter/   ConsoleTablePresenter, JsonPresenter
bin/replay
tests/
```

On hexagonal discipline: the only things genuinely crossing a boundary are the event source
and the presenter. The rules stay in `Domain/` as plain classes — with the policy flags gone
there is nothing to inject, so they are concrete, not interfaces. Wrapping domain arithmetic
in ports, or keeping strategy interfaces with a single implementation each, would both be
cargo-culting and are deliberately avoided; the README states this reasoning explicitly.

Rejections and declines are recorded as append-only `DecisionLog` entries with reasons —
never silently dropped. The `Ledger` holds financial entries only; the `DecisionLog` holds
every event and its outcome.

## The deliberate failing test

Asserts that no day with a non-negative final closing balance carries an overdraft fee. It **fails** for D2, D4, D5 — after E9 those days
close at +225.00, +415.00, +390.00 yet each still carries a −25.00 fee.

Inline annotation explains what it reveals: append-only retroactive assessment can leave
the ledger holding a charge the final state does not justify. Resolving it requires either
an explicit reversal event (absent from the stream) or an auto-reversal rule, which invents
a rule the spec never granted. This is the honest cost of the default, not a bug.

Tagged `@group known-failure` and excluded from the default suite so `composer test` is
green. The brief asks for a failing test *in the repo*, so hiding it must not read as
gaming the requirement: the README states its existence, its command
(`composer test:known-failure`), and its expected output in the "How to run" section — not
in a footnote — and REJECTED.md cross-references it. It is excluded from the default run
only so that a green suite still means "everything I claim works, works".

## NUMBERS.md — the "why not half it" answers

The brief asks for "every constant you *chose*". Most of the interesting ones are **given**
by the spec, not chosen — so NUMBERS.md keeps the two sets in separate sections rather than
blurring them, and answers "why not half it" for both. Presenting a given constant as a
free choice would be the easiest way to look like the analysis was never done.

### Given by the spec — sensitivity analysis

- **Fee 25.00**: load-bearing within 5.00. At close of D5, D3 closes at exactly +5.00.
  A fee of 12.50 leaves D3 at +17.50 (no change to the cascade); a fee above 30.00 drives
  D3 negative and triggers a *fourth* fee. The chosen value sits near a cliff.
- **Rate 0.04%/day** (≈14.6% simple annual): sets a dust threshold. Balances below
  AED 12.50 accrue 0.00 under half-up at 2dp (below BHD 1.25 for 3dp). Halving the rate
  doubles that threshold and silently zeroes more days.
- **3 instalments**: smallest count making 10.000 inexact at 3dp — 2 would split
  5.000/5.000 exactly and test nothing. Forces largest-remainder allocation.
- **6-day window**: must be ≥5 for E7's backdate to disturb settled history, ≥6 for E9's
  reversal to land after assessment. Halving to 3 removes the restatement problem entirely.
- **AED 2dp / BHD 3dp**: ISO 4217, given.

### Chosen by us — the constants the brief is actually asking about

- **HALF_UP**: no accrual in this dataset lands on an exact half — a tie requires a balance
  of the form 25k + 12.50, and none of ours (250, 225, 625, 415, 390, 465, 650, 440, 235,
  210) is one. The result is therefore invariant across the *half-tie* modes (HALF_UP /
  HALF_DOWN / HALF_EVEN). It is **not** invariant against truncation: D4's 0.166 rounds to
  0.17 but truncates to 0.16, moving the capitalized total. Half-up is chosen as the
  banking default; the divergence from truncation is stated rather than glossed as
  "rounding-mode-independent".
- **Largest-remainder tie-break order**: E10's 0.001 residue goes to the *first* instalment
  (3.334 / 3.333 / 3.333), not the last. Chosen for being deterministic and order-stable,
  and for front-loading the credit so the account is never worse off than a strict split.
  Residue-last is arguably the better convention — an amortization schedule puts the
  balancing payment last — but the argument cannot bite here: all three instalments carry
  value_date D5, so no balance, fee or accrual moves whichever end takes the residue
  (AMBIGUITIES §9). Recorded so the golden test reads as a decision rather than an accident.
- **The four policy decisions** (retroactive assessment, reject orphans, fees stand,
  restated interest): each is the reading most faithful to a stated non-negotiable rather
  than to card-network realism, on the grounds that the spec's rules outrank industry
  practice where the two conflict. None is configurable — every alternative is documented in
  AMBIGUITIES.md with the figure it would have produced, so each choice is a stated position
  rather than a buried assumption *or* a fence sat on.
- **No iteration cap**: deliberately absent, per the single-ascending-pass proof above. The
  "why not half it" answer is that any cap > 1 is dead code and a cap of 1 is the proof
  restated as an assert — so the test carries it instead.

## Known ambiguities

Written up in AMBIGUITIES.md, which is authoritative. Index, so this plan stays navigable:

## Verification

- `composer test` — full suite green; the golden test locks the shipped configuration
  end-to-end (per-day balances, fees, authorization states, errors, capitalization).
- **Idempotent replay** — run against the `withDuplicates()` source, which emits each of the ten
  events twice. The `DailyReport` must be identical to the single-emission run (390.93 / 10.008,
  three fees on D2/D4/D5), the ledger must hold the same number of entries, and the
  `DecisionLog` must carry exactly ten `REJECTED_DUPLICATE_EVENT_ID` records. The property worth
  having: *replaying the whole stream twice is a no-op.* It also cross-checks the separate
  "once per day per account" fee guard — a leak in either shows up as a changed fee total.
- `composer test:known-failure` — shows the one deliberate failure with its annotation.
- `php bin/replay` — expect ACC-001 = 390.93, ACC-002 = 10.008, three fees on D2/D4/D5,
  Auth-A approved then settled, Auth-B declined, E6 rejected.
- `php bin/replay --format=json` — machine-checkable output for the golden test.

The counterfactual finals (390.81 / 466.03 / 210.69 / 441.01) are deliberately **not**
reachable from the binary. They are derived arithmetically in AMBIGUITIES.md with the
day-by-day working shown, so nothing in the docs claims a test result the suite never
produced.
