# AMBIGUITIES.md

Every ambiguity found in the brief, how it was resolved, and — where the resolution changes
the numbers — exactly what the rejected reading would have produced.

The kernel ships **one** configuration. There are no policy flags. That is deliberate: an
engine configurable into any answer demonstrates a position on none of them. The cost is that
the counterfactual figures below cannot be produced by the binary, so each is worked through
day by day here and can be checked by hand.

**Shipped result:** ACC-001 closes at **AED 390.93**, ACC-002 at **BHD 10.008**, with three
overdraft fees totalling AED 75.00.

---

# Part 1 — The four load-bearing decisions

## §1 Does a backdated entry reopen an already-closed day for fee assessment?

**The ambiguity.** The fee rule triggers on "that day's closing ledger balance (all entries
with value_date ≤ that day)". E7 is booked on D5 with value_date D2, so on D5 the ledger
learns that D2 was overdrawn all along. Is D2 re-examined, or was its fee decision settled
when D2 closed?

The brief never says. It is the single highest-impact question in the exercise: the answer
sets total fees at 75.00 or 25.00.

**Chosen: reopen.** The rule defines the trigger in terms of `value_date`, and `value_date` is
precisely the dimension E7 moves. D2's closing balance genuinely becomes −370.00 the moment E7
is booked; a rule keyed to that balance has to see the change. A sealed ledger would also make
the entire bitemporal apparatus pointless — E7 and E9 exist in the stream for no other reason
than to move value_date backwards into closed days.

**Rejected: sealed** — a day's fee decision is final once taken. Defensible, and closer to how
some real systems behave (closed accounting periods are not reopened; an adjustment is booked
in the current period instead). It would assess only D5 at its own close, for a single fee.

**What sealed would produce:**

| | D1 | D2 | D3 | D4 | D5 | D6 |
|---|---|---|---|---|---|---|
| closing balance | 250.00 | 250.00 | 650.00 | 465.00 | 440.00 | 440.00 |
| accrual | 0.10 | 0.10 | 0.26 | 0.19 | 0.18 | 0.18 |

One fee, value_date **D5**, 25.00 total. Interest 1.01. **ACC-001 final: 441.01.**

Note this does *not* rescue criterion 2, which claims one fee **on Day 2**. Under sealed the
single fee lands on D5. See REJECTED.md.

## §2 What happens to a settlement with no matching authorization?

**The ambiguity.** E6 settles Auth-Z for 180.00, and no Auth-Z authorization was ever booked.
Criterion 4 says reject it and keep the funds. Card networks say otherwise.

**Chosen: reject, funds stay.** Criterion 4 states it explicitly and it contradicts no
non-negotiable rule. E6 posts nothing; the rejection is recorded in the DecisionLog with a
reason, never silently dropped.

**Rejected: force-post.** In a real network a settlement without a matching authorization is a
force-post or late presentment — the money has already moved and the network has guaranteed
it. Rejecting it produces a reconciliation break, not a saved 180.00. We follow the brief over
production reality here, but the divergence is real and worth stating.

**What force-post would produce:**

| | D1 | D2 | D3 | D4 | D5 | D6 |
|---|---|---|---|---|---|---|
| closing balance | 250.00 | 225.00 | 625.00 | 235.00 | 210.00 | 210.00 |
| accrual | 0.10 | 0.09 | 0.25 | 0.09 | 0.08 | 0.08 |

Still three fees (D2, D4, D5), 75.00. Interest 0.69. **ACC-001 final: 210.69.**

The 180.00 debit lands at value_date D4, which is why D4 and D5 fall by 180.00 while D2 and D3
are untouched.

## §3 When E9 reverses E7, do the fees E7 caused come back too?

**The ambiguity.** E9 says "reverses E7". Does that mean *undo entry E7*, or *restore the
account to the state it would have been in had E7 never happened*? The two differ by 75.00.

**Chosen: fees stand.** E9 names one event ID. Each fee was correct against the ledger state
at the moment it was assessed, and unwinding one requires an explicit adjustment event that
the stream does not contain. Reading "reverses E7" as "reverses E7 and every consequence of
E7" would be inventing a rule the brief never granted.

**Rejected: auto-reverse** — when a reversal removes the cause of a fee, append a compensating
entry for the fee. This is what many banks actually do as a goodwill or error-correction
policy, and it produces the more intuitively "fair" ledger.

**What auto-reverse would produce:**

| | D1 | D2 | D3 | D4 | D5 | D6 |
|---|---|---|---|---|---|---|
| closing balance | 250.00 | 250.00 | 650.00 | 465.00 | 465.00 | 465.00 |
| accrual | 0.10 | 0.10 | 0.26 | 0.19 | 0.19 | 0.19 |

Zero fees. Interest 1.03. **ACC-001 final: 466.03.**

This counterfactual matters beyond its own merits: D1–D5 return to exactly the pre-E7 figures,
which is what disproves the tempting-but-wrong argument that append-only forbids balances from
returning to prior values. See REJECTED.md, criterion 6.

**The cost of choosing "stand" is visible and deliberate.** After E9, D2 / D4 / D5 close at
+225.00 / +415.00 / +390.00 — all positive — yet each still carries a −25.00 fee. The
committed failing test asserts exactly this and fails on all three days.

**Sub-ambiguity — a fee reversal's value_date.** Had we auto-reversed, the compensating entry
could carry the original fee's value_date or the day of reversal. We would use the original
day, keeping the pair netted within the period. Not exercised in the shipped build.

## §4 Are interest accruals restated when backdated entries arrive?

**The ambiguity.** Accruals are computed daily but capitalize as a single credit at the end of
D6. When E7 and E9 change historical balances, does each day's accrual get recomputed against
final knowledge, or does it stand as it was calculated at that day's close?

**Chosen: restated.** Accruals are not booked entries until the D6 capitalization, so
recomputing them mutates no record and violates no append-only rule. Capitalization happens at
D6, when every day's balance is best-known. The result is one credit of 0.93 at value_date D6:

| | D1 | D2 | D3 | D4 | D5 | D6 |
|---|---|---|---|---|---|---|
| closing balance | 250.00 | 225.00 | 625.00 | 415.00 | 390.00 | 390.00 |
| accrual | 0.10 | 0.09 | 0.25 | 0.17 | 0.16 | 0.16 |

0.10 + 0.09 + 0.25 + 0.17 + 0.16 + 0.16 = **0.93**. ACC-001 final: **390.93**.

**Rejected: as-known** — accrue against each day's balance as known at that day's own close,
and never revisit. Closer to how a bank actually accrues nightly, and it has the virtue that a
printed daily accrual is never contradicted later.

**What as-known would produce:**

| | D1 | D2 | D3 | D4 | D5 | D6 |
|---|---|---|---|---|---|---|
| accrual basis | 250.00 | 250.00 | 650.00 | 465.00 | **−230.00** | 390.00 |
| accrual | 0.10 | 0.10 | 0.26 | 0.19 | **0.00** | 0.16 |

Interest 0.81. **ACC-001 final: 390.81.**

The D5 cell is the tell: as-known accrues 0.00 for D5 because at D5's close the day was
−230.00, yet the final ledger shows D5 at +390.00. The account earns nothing for a day it
ended up in credit on. That asymmetry is why we restate.

---

# Part 2 — Resolved without a numerical fork

## §5 A fee's own value_date

"Booked with value_date equal to the day assessed" is unambiguous only while assessment is
same-day. Once a backdated entry reopens D2 on D5, "the day assessed" could mean the day whose
balance was negative (D2) or the day the assessment ran (D5).

**Resolved: the day whose balance was negative.** It keeps the charge in the period that
caused it, and criterion 2's own phrasing ("a fee assessed *on Day 2*") assumes it. The
alternative would pile D2's and D4's fees onto D5 and change the cascade, since a fee at
value_date D5 does not affect D3's or D4's balance.

## §6 Which closing balance the per-day output prints

The brief requires per-day closing balances from a ledger where "Day 2's closing balance"
provably has more than one correct value:

| Query | E9 known? | fee included? | D2 |
|---|---|---|---|
| end of D5, pre-fee | no | no | −370.00 |
| end of D5, post-fee | no | yes | −395.00 |
| end of D6 | yes | yes | +225.00 |

**Resolved: print both dimensions.** `DailyReport` carries the balance as known at that day's
own close *and* as restated against final knowledge, and the presenter prints both columns.
Collapsing to either alone would conceal the exact behaviour E7 and E9 exist to expose.

## §7 Restated accruals must also restate the printed output

Under §4's resolution, the accrual printed at D2's close (0.10, against 250.00) is superseded
by the restated 0.09 (against 225.00). If the report printed the original series, the printed
dailies would not sum to the capitalized 0.93 — the non-negotiable would hold internally but
be violated on the face of the output, which is the artefact being read.

**Resolved:** the final report reprints the restated accrual series. The per-day figures a
reader adds up are the ones that produce the capitalized credit.

## §8 Interest circularity on Day 6

Capitalizing 0.93 at value_date D6 would alter D6's own closing balance, which is the basis
the D6 accrual is computed from.

**Resolved:** D6 accrues on its balance *before* capitalization (390.00 → 0.16). The
capitalization credit is booked after all accruals are fixed. Otherwise the calculation has no
fixed point.

## §9 "Three equal instalments" is unsatisfiable

BHD carries 3 decimal places, and 10.000 / 3 = 3.333… is not representable. No three equal 3dp
amounts sum to 10.000, so the instruction cannot be followed literally.

**Resolved: largest-remainder allocation** — 3.334 / 3.333 / 3.333 = 10.000 exactly, with the
0.001 residue going to the first instalment. Deterministic and order-stable; residue-last is
equally defensible and gives the same total. See REJECTED.md, criterion 7.

## §10 Stream ordering

E10 is booked Day 5 but appears in the listing after E9, which is booked Day 6.

**Resolved:** stable-sort by booked day, preserving the given order within a day. E10 is
processed with the other Day 5 events. It touches only ACC-002, so ACC-001 is unaffected
either way — but leaving the order to chance would be a latent bug.

## §11 Backdated debits bypass the available-balance check

The brief mandates an available-balance test for **authorizations** only. E7 is a debit, and
it posts regardless of the −370.00 it creates at D2.

**Resolved:** no balance check on debits. This is not an oversight in the brief — it is what
makes the overdraft cascade possible at all, and a real ledger does post settled debits that
overdraw an account.

## §12 Reversal guards

The brief specifies one reversal and says nothing about malformed ones.

**Resolved:** reversing an unknown entry, and reversing an entry already reversed, are both
rejected and recorded in the DecisionLog with reasons. Neither occurs in the given stream;
both are guarded so that the append-only rule cannot be subverted by a double-credit.

## §13 Zero balances do not accrue

"Positive balances only" — ACC-002 sits at 0.000 for D1–D4. Zero is not positive.

**Resolved:** no accrual on D1–D4. ACC-002 accrues only on D5 and D6 (0.004 each), capitalizing
0.008 for a final of **BHD 10.008**.

## §14 Criterion 5 names Auth-B, whose "if" never fires

*"If Auth-B is approved, its hold reduces available balance but not ledger balance."*

The rule stated is correct and we implement it. But Auth-B is never approved: at E8 available
is −155.00, and a 90.00 hold takes it to −245.00, so the non-negotiable available-balance test
refuses it. The conditional's antecedent never fires, which makes the criterion vacuously true.

Two explanations, and the brief does not let us tell them apart:

- **Deliberate.** A sound conditional with a dead antecedent, planted to see whether a candidate
  refuses a criterion that is actually correct. Over-refusing fails the exercise as surely as
  missing a bad criterion.
- **A slip for Auth-A.** With Auth-A the antecedent fires and the claim becomes live and
  checkable: at E3 the ledger balance stays 250.00 while available drops to 50.00.

One detail leans toward the second. The stream's closing note reads *"Auth-B is never settled
inside the window."* A declined authorization cannot settle, so that sentence only carries
information if its author expected Auth-B to be approved and left holding at D6. Under the rules
as written it cannot be — the available-balance test is non-negotiable and E7's backdated debit
has already pushed the account to −155.00 by the time E8 is evaluated.

**Resolved: it makes no difference, so we do not rewrite the brief.** The rule is implemented and
demonstrated on Auth-A, where the antecedent genuinely holds; Auth-B is declined and the decline
is logged with its −155.00 available balance. The criterion is accepted under either reading —
see REJECTED.md §5.

---

# Counterfactual summary

Each row changes exactly one decision from the shipped configuration.

| Decision changed | § | Fees | Interest | ACC-001 final | |
|---|---|---|---|---|---|
| *(none — shipped)* | — | 75.00 | 0.93 | **390.93** | built |
| assessment → sealed | §1 | 25.00 | 1.01 | 441.01 | derived |
| orphan → force-post | §2 | 75.00 | 0.69 | 210.69 | derived |
| fees → auto-reverse | §3 | 0.00 | 1.03 | 466.03 | derived |
| interest → as-known | §4 | 75.00 | 0.81 | 390.81 | derived |

Only the first row is executable. The other four are derived from the day-by-day tables above
and are stated as derivations, not as test results.
