# Build

### An in-memory account ledger core. Any language. No web layer, no persistence, no UI, no database. It must be exercised by a runnable test suite or script that replays the event stream and prints, per day: closing ledger balance, fee assessments, authorization states, and errors.

The window is six days, Day 1 through Day 6.

### Accounts:
- ACC-001 — AED, opening balance 0.00
- ACC-002 — BHD, opening balance 0.000

### Non-negotiable rules:
- Overdraft fee: AED 25.00, assessed once per day per account when that day's closing ledger balance (all entries with value_date ≤ that day) is negative. Booked with value_date equal to the day assessed.
- Daily interest: 0.04% per day on the closing ledger balance, positive balances only. Accruals capitalize as a single credit at end of Day 6. The rounded daily accruals must sum exactly to the capitalized total.
- AED is 2 decimal places, BHD is 3. Amounts stored and rounded to their own precision.
- The ledger is append-only. No event record is ever mutated or deleted.
- An authorization is approved only if the account's available balance — ledger balance minus active holds — remains at or above zero after the hold is applied.

### Event stream, replayed in this order:

- E1 — Day 1 — CREDIT — ACC-001 AED 1,200.00 — value_date Day 1
- E2 — Day 1 — DEBIT — ACC-001 AED 950.00 — value_date Day 1
- E3 — Day 2 — AUTHORIZATION — ACC-001 Auth-A hold AED 200.00 — value_date Day 2
- E4 — Day 3 — CREDIT — ACC-001 AED 400.00 — value_date Day 3
- E5 — Day 4 — SETTLEMENT — ACC-001 Auth-A settles for AED 185.00 — value_date Day 4
- E6 — Day 4 — SETTLEMENT — ACC-001 Auth-Z settles for AED 180.00 — value_date Day 4 (Auth-Z has no preceding authorization event)
- E7 — Day 5 — DEBIT — ACC-001 AED 620.00 — value_date Day 2
- E8 — Day 5 — AUTHORIZATION — ACC-001 Auth-B hold AED 90.00 — value_date Day 5
- E9 — Day 6 — REVERSAL — ACC-001 reverses E7 — value_date Day 2
- E10 — Day 5 — CREDIT — ACC-002 BHD 10.000, posted as three equal instalments — value_date Day 5

Auth-B is never settled inside the window.


### Acceptance criteria
Some of the following criteria are wrong. Identify every incorrect criterion, refuse it, and document your reasoning in REJECTED.md.

1. The Day 2 closing ledger balance, evaluated at end of Day 5 and before any fee is assessed, is AED −370.00.
2. E7 causes exactly one overdraft fee to be assessed, on Day 2.
3. The Day 4 settlement of Auth-A must be accepted.
4. Any settlement referencing an authorization ID not present in the ledger must be rejected and the funds must not leave the account.
5. If Auth-B is approved, its hold reduces available balance but not ledger balance.
6. After E9, all balances and fees return to their pre-E7 values.
7. The three BHD instalments in E10 must each be BHD 3.334. 
8. If the rounded daily interest accruals do not sum to the capitalized total, the remainder is discarded.

### Deliverable 1 — repository

- Intact commit history, no squashing. Plus:- README — how to run the suite and read the output

- NUMBERS.md — every constant you chose, why that value and not half it
- AMBIGUITIES.md — every ambiguity you found and how you resolved it; near-empty is a fail
- REJECTED.md — criteria refused with reasons, plus approaches abandoned mid-build 
- One failing test against your own design, inline-annotated with what it reveals
- WORKLOG.md — timestamped, real