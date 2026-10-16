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

## Repayment — IMPLEMENTED (tested), treatments PROPOSED
`GET /api/loans/{id}/repayment-quote`, `POST /api/loans/{id}/repayments` `{amount, channel: cash|savings, savings_account_id?, source_of_funds?}` + `Idempotency-Key`.
- Interest: outstanding × rate × actual days ÷ 365 for (interest paid through, business date]
  (Std 5/2.3); never added to principal (Act s.50(5)).
- Penalty: product % (≤ 5) of the *overdue* interest — interest for days up to the latest due
  date already passed (Act s.50(5क)).
- Allocation: penalty → interest → principal; a payment must cover penalty and interest in full;
  principal settles the oldest instalments first; overpayment is refused.
- Income on receipt (cash basis, consistent with NCRA D1/D2): Dr cash or savings / Cr loans
  receivable, interest income, penalty income. Cash repayments go to the threshold register;
  source of funds from Rs 10 lakh (AML Instruction 8(ग)).
- From savings: borrower's own account, available balance only. Full repayment closes the loan
  and releases the lien.

## Classification and provisioning — IMPLEMENTED (tested)
Daily at day close: each open loan is classified by its oldest overdue instalment (principal
unpaid or interest not paid through a passed due date): Pass ≤ 3 months, Substandard ≤ 6,
Doubtful ≤ 12, Loss > 12 (Std 3/3); loans secured by the member's savings are always Pass.
Required provision = 1 / 25 / 50 / 100 % of outstanding principal (Std 3/4.1). PROPOSED: the
difference against the provision account balance is posted at each BS month-end close and on
demand (`POST /api/loan-provisioning`, permission `loan.provision`): Dr provision expense /
Cr loan loss provision (reverse for write-back).

Not covered yet: director / CEO / staff restrictions (Std 3/1.4, 7/4.1, 7/5.4 — needs governance
data), CIC credit check thresholds (unverified; a CIC report reference can be recorded),
productive-sector 50% ratio report (Std 3/1.7), collateral release letters, foreclosure, write-off.
