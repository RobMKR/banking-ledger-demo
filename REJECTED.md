# REJECTED.md

Criteria refused, and approaches abandoned during the build.
Criteria are numbered as in `assessment_plain.md`. Full arithmetic lives in AMBIGUITIES.md.

Four criteria are refused:

| # | Claim | Grounds |
|---|---|---|
| 2 | E7 causes exactly one fee, on Day 2 | Three fees, and never one on Day 2 |
| 6 | After E9, all balances and fees return to pre-E7 values | E9 reverses E7, not its fees |
| 7 | The three BHD instalments must each be 3.334 | 3 × 3.334 = 10.002 ≠ 10.000 |
| 8 | Discard the remainder if accruals don't sum to the total | Contradicts a non-negotiable rule |

Criteria 1, 3, 4 and 5 are accepted. Criterion 4 is followed with a caveat about card-network
behaviour (AMBIGUITIES §2), and criterion 5 is sound but has an antecedent that never fires
(AMBIGUITIES §12) — neither is refused, so neither is argued here.

---

## 2 — "E7 causes exactly one overdraft fee to be assessed, on Day 2."

**Refused.** But the sentence is ambiguous, so start there.

**(a)** E7 causes **one fee in total**, and it falls on Day 2.
**(b)** **Day 2 receives exactly one fee** — saying nothing about other days.

We refuse under **(a)**: "exactly one" attaches to what *E7 causes*, and "on Day 2" locates
that fee. (a) is also the only reading that states something testable — the fee rule already
assesses "once per day per account", so no day can *ever* receive two fees. Under (b) the
words "exactly one" assert nothing the rule didn't already guarantee.

**The cascade.** Before E7 nothing is overdrawn: D1–D4 close at
250.00 / 250.00 / 650.00 / 465.00. E7 (booked D5, value_date D2) creates the overdraft:

| Day | balance | fee? | after |
|---|---|---|---|
| D2 | −370.00 | yes | −395.00 |
| D3 | +5.00 | no | +5.00 |
| D4 | −180.00 | yes | −205.00 |
| D5 | −205.00 | yes | −230.00 |

Three fees — D2, D4, D5 — totalling **75.00**.

If instead closed days are never reopened (the reading we rejected, AMBIGUITIES §1), only D5
is assessed: −155.00 → one fee, value_date **D5**. D2 is never revisited, so it gets none.

**Both readings, both assessment policies:**

| | (a) E7 → one fee, on D2 | (b) D2 gets exactly one fee |
|---|---|---|
| Retroactive *(shipped)* | ✗ three fees — D2, D4, D5 | ✓ true, but guaranteed by the once-per-day rule |
| Sealed *(rejected)* | ✗ one fee, but on **D5** | ✗ D2 gets **zero** |

Reading (a) is false either way. Reading (b) is true only under the configuration we happened
to ship, and asserts nothing on its own. The claim is either false or empty.

> Deliberately *not* refused on "there are three fees" — that is true only under the reading
> we chose, and the refusal would collapse if the assessor intended the other one.

## 6 — "After E9, all balances and fees return to their pre-E7 values."

**Refused.** E9 reverses **E7**, not E7's consequences. It appends +620.00 at value_date D2
and names nothing else — so the three fees stay.

| | D1 | D2 | D3 | D4 | fees |
|---|---|---|---|---|---|
| pre-E7 | 250.00 | 250.00 | 650.00 | 465.00 | 0.00 |
| post-E9 | 250.00 | **225.00** | **625.00** | **415.00** | **75.00** |

D2 is the clearest case. E7 (−620.00) and E9 (+620.00) cancel exactly; the −25.00 fee does
not, because no event reverses it. 250.00 − 25.00 = **225.00**.

Two grounds:
1. No fee-reversal event exists in the stream, and no rule says reversing a debit unwinds
   fees the debit caused.
2. The fee entries are permanent under append-only, so those charges cannot cease to exist.

> Deliberately *not* refused on "append-only forbids balances returning to prior values" —
> that is false. Appended compensating entries can restore a balance, and an auto-reversing
> rule would put D1–D5 back at exactly the pre-E7 figures (AMBIGUITIES §3).

**This refusal has a cost, and it is committed as a failing test.**
`tests/KnownFailure/FeesOutliveTheirCauseTest.php` asserts that no day ending in the black
carries a fee, and fails on exactly these three days. Refusing criterion 6 means accepting a
ledger that holds a charge its own closing balance cannot account for; the test is the receipt,
and its inline annotation works through both ways out and why each is worse. The README explains
how to run it.

## 7 — "The three BHD instalments in E10 must each be BHD 3.334."

**Refused.** 3 × 3.334 = **10.002**, overpaying the 10.000 credit by 0.002.

BHD has 3 decimals and 10.000 / 3 = 3.333…, so *no* three equal amounts sum to 10.000. The
criterion resolves that impossibility by rounding all three up, and breaks the total.

Used instead: **3.334 + 3.333 + 3.333 = 10.000**, exact, by largest remainder.

## 8 — "If the rounded daily accruals do not sum to the capitalized total, discard the remainder."

**Refused.** It contradicts a non-negotiable rule, which states the accruals **must** sum
exactly to the capitalized total. A criterion does not override a rule declared non-negotiable.

It is also incoherent: the capitalized total *is* the sum of the dailies
(0.10 + 0.09 + 0.25 + 0.17 + 0.16 + 0.16 = **0.93**). There is no remainder to discard.

---
# Approaches abandoned mid-build

Not criteria — decisions taken during the build and then reversed. Recorded because a design
that only shows its final state hides where the thinking actually happened.

**Policy flags for the four unresolved ambiguities.** The plan carried four switches so every
reading could be executed. Cut, because a configurable engine can pass every combination and
still demonstrate no position on any of them. One hardcoded path plus a worked counterfactual
shows both the decision and the understanding behind it. The cost is real and is stated in
AMBIGUITIES.md: the four alternative finals (390.81 / 466.03 / 210.69 / 441.01) are derived by
hand and **cannot be produced by this binary**.

**A fixpoint loop with a convergence guard and an iteration cap** for fee assessment. Dropped
once the proof was written out: a fee books with `value_date` equal to the day whose balance was
negative, so it can only ever affect days at or after that day. A single ascending pass D1→D6
*is* the fixpoint. The cap would have been a runtime assert against a loop that cannot occur.
Replaced by a test that a second pass raises nothing and ten more change no count.

**`AuthorizationRule::authorize()` as a public method that placed the hold.** It reserved funds
and returned a verdict rather than a `Decision`, so any caller could change an account's
available balance without producing a log record — a hole through the guarantee the DecisionLog
exists for. Split into a pure `assess()` (safe to expose precisely because it changes nothing)
and `apply()` as the only mutator, which cannot reserve without also returning the record.

**`symfony/console` for the CLI.** Planned, then dropped. What it buys here is argument parsing
for one optional flag, against a runtime dependency in a project whose argument is that the
arithmetic is the deliverable. `getopt()` is four lines.

**A DI container.** Same test, same answer. A container earns its place when wiring is large,
conditional, or configured at runtime; this graph is fifteen objects in one arrangement.
Reflection-driven autowiring would replace a list you can read with a magic you cannot. Kept
constructor injection throughout and one hand-written composition root.

**Ports in front of everything.** The plan had interfaces for the event source and the
presenter. Only the presenter kept one, because only the presenter has two implementations
(console and JSON). An interface with a single implementation is a seam with nothing to pass
through it — the same reasoning that keeps the rules concrete classes.

**Payload hashing in the duplicate guard**, to tell a benign retry from a same-id-different-payload
integrity breach. Dropped as scope creep: the stream contains neither case and nothing here would
act on the distinction. The limitation is real, so it is tested rather than glossed — an event
reusing an id with a different payload is silently absorbed, and a test says so out loud.
