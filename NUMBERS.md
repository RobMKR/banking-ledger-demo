# NUMBERS.md

Every constant in the ledger, and why that value rather than half it.

The brief asks for "every constant **you chose**". Most of the interesting ones are *given* by
the spec, so the two sets are kept apart rather than blurred — presenting a given constant as a
free choice would be the easiest way to look like the analysis was never done. Both sets get
the sensitivity treatment.

Claims marked **(tested)** are asserted by the suite, not just argued here.

---

# Part 1 — Given by the spec

## Overdraft fee: AED 25.00

Load-bearing, with a margin of exactly 5.00.

During the Day 5 cascade, D3 sits at `−370.00 − fee + 400.00`. That is what decides whether the
overdraft spreads to a fourth day:

| Fee | D3 at close of D5 | Fees assessed | ACC-001 final |
|---|---|---|---|
| 12.50 (half) | +17.50 | 3 — D2, D4, D5 | 428.48 |
| **25.00** | **+5.00** | **3 — D2, D4, D5** | **390.93** |
| 30.00 | 0.00 | 3 — D2, D4, D5 | 375.90 |
| 30.01 | −0.01 | **4** — D2, D3, D4, D5 | 345.82 |
| 50.00 (double) | −20.00 | 4 — D2, D3, D4, D5 | 265.75 |

Halving it changes no structure — the same three days are charged. **Doubling it does**: anything
above 30.00 pushes D3 negative and triggers a fourth fee, which then cascades further. The given
value sits 5.00 below a cliff, close enough that the cascade is worth testing and far enough that
it does not trip.

Note the boundary is strict. At exactly 30.00 D3 closes at 0.00, which is not negative, so no fee
is assessed — the rule says "when that day's closing balance is negative".

## Daily interest: 0.04% per day (4 basis points)

Roughly 14.6% simple annual. Two things depend on the magnitude.

**A dust threshold.** A balance below **AED 12.50** accrues 0.00 at two decimal places; below
**BHD 1.25** at three. **(tested** — `RateTest::testAedDustThresholdSitsAtTwelveFifty`,
`RateTest::testBhdDustThresholdSitsAtOnePointTwoFive`**)**

| Rate | Dust threshold (AED) | Interest total | Any balance tie? |
|---|---|---|---|
| 0.01% (quarter) | 50.00 | 0.23 | ties at 250.00, 650.00 |
| 0.02% (half) | 25.00 | 0.47 | **ties at 225.00, 625.00** |
| **0.04%** | **12.50** | **0.93** | **none** |
| 0.08% (double) | 6.25 | 1.83 | none |

**Halving the rate makes the rounding mode load-bearing.** At 0.02%, D2's balance of 225.00
accrues exactly 0.045 — a true tie. HALF_UP gives 0.05; HALF_DOWN and HALF_EVEN both give 0.04,
and the capitalized total changes with it. At the given 0.04% no balance in the ledger ties at
all, which is precisely why the tie-breaking rule can be documented as *not* load-bearing here.
**(tested** — `RoundingTest::testNoAccrualInTheAssessmentDatasetLandsOnATie`**)**

That is the sharper answer to "why not half it": halving does not merely shrink the numbers, it
moves a rounding-policy decision from inert to decisive.

## Three instalments

The smallest count that makes BHD 10.000 inexact at three decimal places.

| Parts | Split | Exact? |
|---|---|---|
| 2 | 5.000 / 5.000 | yes — tests nothing |
| **3** | **3.334 / 3.333 / 3.333** | **no — forces largest-remainder** |
| 4 | 2.500 × 4 | yes |
| 5 | 2.000 × 5 | yes |

Halving to 2 would divide evenly and exercise no allocation logic at all. Three is the smallest
value that makes the residue real, which is what allows criterion 7 to be refused with
arithmetic rather than opinion. **(tested** — `AllocatorTest`**)**

## Six-day window

| Window | Fees | What is lost |
|---|---|---|
| 3 days (half) | none | E7 and E9 never arrive; no backdating at all |
| 4 days | none | still nothing overdrawn |
| 5 days | D2, D4, D5 | E7 lands, but E9 never does — the ledger ends at −230.00 |
| **6 days** | **D2, D4, D5** | — |

The window must reach **5** for E7's backdated debit to disturb settled history, and **6** for
E9's reversal to land *after* the fees were assessed. Halving it to three removes the entire
restatement problem: no fee is ever charged and the bitemporal machinery is never exercised.

## AED 2 decimals, BHD 3 decimals

ISO 4217, given. Not a choice — but it is the reason precision is per-currency rather than
global. BHD's third place is what makes 10.000 / 3 inexact, and `Money` refuses `'3.334'` as
AED while accepting it as BHD. **(tested** — `MoneyTest::testAedRefusesAThirdDecimalPlaceThatBhdWouldAccept`**)**

---

# Part 2 — Chosen by us

## HALF_UP, read as away-from-zero

Matches Java's `RoundingMode.HALF_UP` and PHP's own `round()`. Distinguished from HALF_EVEN by
2.5 → 3, and applied symmetrically so −2.5 → −3. **(tested** — `RoundingTest::testTiesRoundAwayFromZero`**)**

**Not load-bearing at the given rate**, since nothing ties — but it is *not* invariant against
truncation: D4's 0.166 rounds to 0.17 and truncates to 0.16, which moves the capitalized total
from 0.93 to 0.92. "Rounding-mode-independent" would be too strong a claim; independence holds
across the half-tie family only.

## Residue placement: earliest parts first

The 0.001 left over from BHD 10.000 / 3 goes to the first instalment. Deterministic and
order-stable, and it never leaves the account worse off than a strict split.

Residue-last is arguably the better convention — an amortization schedule puts the balancing
payment last. It cannot matter here: all three instalments of E10 carry value_date D5, so they
land on the same day and net to 10.000 either way. No balance, fee or accrual moves.
See AMBIGUITIES.md §9.

## The four policy decisions

Retroactive assessment, reject orphan settlements, fees stand, restated interest. Each is the
reading most faithful to a stated non-negotiable rather than to card-network realism, on the
grounds that the spec's rules outrank industry practice where they conflict.

None is configurable. The "why not the other value" answers are the counterfactual finals —
441.01, 210.69, 466.03, 390.81 — each derived day by day in AMBIGUITIES.md §1–§4.

Confidence is not uniform across the four: §4 (restated interest) is held with materially less
than the other three, and says so.

## No iteration cap on fee assessment

Deliberately absent. A fee books at the value_date of the day that was negative, so it can only
affect days ≥ that day — never an earlier one. A single ascending pass D1→D6 therefore *is* the
fixpoint.

The "why not half it" answer: any cap above 1 is dead code, and a cap of 1 is the proof restated
as an assertion. A test that a second pass is a no-op carries it instead.

---

# Part 3 — Representation

## 64-bit integer minor units, not GMP or BCMath

`Money` holds a signed `int` count of minor units. Headroom against the largest figure the
ledger holds:

| | Representable | Largest here | Slack |
|---|---|---|---|
| AED (2dp) | 9.2 × 10¹⁶ | 1,200.00 | ~14 orders of magnitude |
| BHD (3dp) | 9.2 × 10¹⁵ | 10.000 | ~15 orders of magnitude |

GMP and BCMath both solve a magnitude problem that does not exist here, at the cost of a runtime
extension the assessor would have to have installed. Halving the width *would* matter — 32-bit
integers cap AED at about 21 million, which a real ledger reaches — but PHP's `int` is 64-bit on
every platform this runs on.

**The risk that does exist is silent int-to-float promotion.** PHP turns integer overflow into a
`double` without warning, which would destroy exactness invisibly. Every arithmetic path checks
`is_int()` on its result and raises instead. **(tested** — overflow cases in `MoneyTest`, `RoundingTest`**)**

`PHP_INT_MIN` is refused at construction: its magnitude is not representable, so negation — which
reversals depend on — would silently wrap.

## Basis points for the rate

0.04% is exactly **4 basis points**, and one basis point is exactly 1/10000. Holding the rate as
an integer numerator over a fixed denominator keeps it exact; `0.0004` as a float is not
representable in binary and would put a rounding error upstream of every accrual.

## Input precision is refused, never rounded

`Money::of('3.3333', BHD)` throws. Rounding is permitted at exactly one named boundary —
`Rounding::divideHalfUp()` — and a constructor that quietly rounded would become a second,
unnamed one. **(tested** — `MoneyTest::testRefusesMorePrecisionThanTheCurrencyHolds`**)**
