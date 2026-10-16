# Loans (Phase 8–9)

Status: 2026-10-16. **IMPLEMENTED (tested)** unless marked. Rule IDs refer to `regulatory-register.md`.

## Products (`/settings/loan-products`, permission `loan.product_manage`)
Collateral type: own savings, real estate, or unsecured (guarantors). Gold-collateral and
overdraft loans cannot be created (Std 9/8). Annual rate, penalty (≤ 5% of overdue interest,
Act s.50(5क), database CHECK), admin service fee %, monthly or quarterly repayment (principal at
least every 3 months, Std 3/1.8), equal principal or equal instalment, maximum term, grace and
amount; real-estate products need the cooperative's maximum loan-to-value %.
Register L-06 (60% / 70% by local level, CHECK SCAN) is not hard-coded: the product field holds
the cooperative's policy value.

## Application → decision → disbursement
Checked at application, approval and disbursement:
| Rule | Source |
|---|---|
| Active members only | Act s.50(1) |
| ≥ 3 months of membership; ≥ 6 for unsecured | Std 3/1.1; Act s.50(8) |
| All the member's loans ≤ 15% of primary capital | Act s.52(2); Std 3/1.2 |
| Savings-secured ≤ 90% of the pledged own savings | Std 3/2.1 |
| Unsecured: regular saver, ≤ lower of 5 × savings and Rs 3 lakh, ≥ 2 other active member guarantors | Std 3/1.3 |
| Real estate ≤ product LTV % of valuation | Std 3/2.2 (cooperative policy) |
| Within product amount, term and grace | product |
| Approver ≠ person who entered the application | PROPOSED control |
| Disbursed only into the borrower's own active savings account | Std 8/10 |
| Only an admin service fee at disbursement | Std 5/2.2 |

PROPOSED postings: disbursement = Dr loans receivable (principal) / Cr borrower's savings
(principal − fee) / Cr fee income (fee). Savings-secured loans put a lien (hold) of
principal ÷ 90% on the pledged account. Schedule due dates in AD calendar months from
disbursement; grace months are interest-only; interest shown in the schedule is an estimate —
interest is charged daily on the outstanding balance (Std 5/2.3) at repayment.

Not covered yet: director / CEO / staff restrictions (Std 3/1.4, 7/4.1, 7/5.4 — needs governance
data), CIC credit check thresholds (unverified; a CIC report reference can be recorded),
productive-sector 50% ratio report (Std 3/1.7), collateral release letters, foreclosure, write-off.
