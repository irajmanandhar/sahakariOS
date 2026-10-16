# Shares and Savings (Phase 5–6)

Status: 2026-10-16. **IMPLEMENTED (tested)** unless marked. Sources: `regulatory-register.md`.

## Ledger roles
Each cooperative maps roles to its own accounts (`/settings/ledger`, `PUT /api/ledger-mappings`):
cash, share capital, reserve fund, savings interest expense, loan interest/penalty/fee income,
loan loss provision and its expense. Each role checks the account type. Savings products name
their own liability account.

## Shares (Act s.63)
`POST /api/members/{id}/shares` `{units, source_of_funds?}` + `Idempotency-Key`.
- Rs 100 per share; active members only; one member ≤ 20% of all shares after the purchase.
- Source of funds required from Rs 10 lakh (AML Instruction 8; Act s.52क says "more than" — the
  stricter "10 lakh or more" is used, register K-12).
- PROPOSED posting: Dr cash / Cr share capital. Share refund on exit: PLANNED.

## Savings
Products (`/settings/savings-products`): general, regular, term (1–36 months); annual rate,
interest posting frequency, minimum balance — all entered by the cooperative (board decision).

Rules on cash deposits and withdrawals:
| Rule | Source |
|---|---|
| Members only, active accounts only | Act s.50(1) |
| Member's total savings ≤ 10% of primary capital at the last Shrawan 1 / Magh 1 | Act s.52(1); Std 2/2.1 |
| Total savings ≤ 15 × primary capital | Act s.50(7); Std 2/1.2 |
| Source of funds from Rs 10 lakh | AML Instruction 8 |
| Cash withdrawal above Rs 5 lakh: written reason + `savings.large_cash_withdrawal` | AML Guidance (Gazette, 15 Jan 2026) |
| No overdraft; balance ≥ 0 and hold ≤ balance (database CHECK) | Std 9/8 |
| Available = balance − hold − product minimum balance | product setting |
| Term savings: no withdrawal before maturity | PROPOSED product rule |

PROPOSED: primary capital = share capital + reserve fund (Act s.2(ङ); register S-04 conflict).
When the ledger has no history before the last count date, the current amount is used.
PROPOSED: term maturity counted in AD calendar months (BS months fail beyond the verified BS
calendar). PROPOSED postings: deposit Dr cash / Cr product account; withdrawal the reverse.

Guarantees: one transaction locks the account row, posts the journal, writes the sub-ledger row
and updates the balance. Proven with 8 parallel processes (only the withdrawals that fit
succeed); without the row lock the test shows lost updates. `ledger:verify` checks savings
balances against the general ledger.

Every cash amount in or out is recorded per member and business date; the threshold report
(`GET /api/reports/threshold-transactions`, permission `aml.view`) lists members with Rs 10 lakh
or more cash in, or out, on one day, with the 15-day report-by date.

## Savings interest — IMPLEMENTED (tested), all treatments PROPOSED
Runs at **day close** (`POST /api/business-day/close`) inside the same transaction:
- Daily accrual: balance × annual rate ÷ 365 per calendar day, 6 decimals, logged in
  `savings_interest_accruals` (append-only). Days without a business day accrue at the balance
  carried into the next business day; the closing day uses its closing balance.
- Posting dates (BS): monthly = month end; quarterly = end of Asoj, Poush, Chaitra, Asar;
  half-yearly = Poush, Asar; yearly = Asar; at maturity. A posting date inside a gap of
  non-business days is still posted at the next close.
- Posting: rounded half-up to paisa, remainder carried forward; Dr savings interest expense /
  Cr product account; idempotent per account and period (a retried close posts nothing twice).
- Interest is expensed when posted, not accrued in the general ledger daily — accountant to
  confirm. **No tax deducted at source (TDS)** — needs tax advice before production.

PLANNED: transfers, account closure, premature term withdrawal, freezing (sanctions), regular
savings ≥ 25% ratio report, teller tills.
