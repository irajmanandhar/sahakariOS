# Financial statements and regulatory returns (Phase 10)

Status: 2026-10-16. All figures are computed live from journal lines and sub-ledgers
(`App\Reports\ReportBuilder`); nothing is typed in. Page: `/reports` (permission `report.view`).
Formats are PROPOSED until NCRA's official templates and the regulator's financial statement
format (register R-09) are obtained.

| Report | Rule checked | Source |
|---|---|---|
| Balance sheet | assets = liabilities + equity + unclosed profit | double entry |
| Profit and loss (default: fiscal year to date) | — | — |
| Loan classification and provision (Table 3.2) | provision held ≥ 1/25/50/100 % minimum | Std 3/4.1 |
| Liquid assets (Table 4.1) | liquid assets ≥ 15% of savings, average of 4 weekly snapshots | Std 4 |
| Capital fund / RWA (Tables 6.1/6.2) | core ≥ 4% of RWA; total ≥ 8% for large cooperatives | Std 6 |
| Interest rates and spread | weighted loan rate − weighted savings rate ≤ 6% | Act s.50(4); Std 5/1.3 |
| Portfolio ratios | regular savings ≥ 25% of savings; productive loans ≥ 50% (large) | Std 2/3.1, 3/1.7 |
| Threshold transactions | cash in/out ≥ Rs 10 lakh per member per day; report within 15 days | AML Instruction 12 |

Accounts are classified for these returns with **report tags** (ledger settings page): bank
deposit, cooperative bank deposit, government bond, fixed deposit (90%, capped at 5% of savings),
other unions, loss compensation fund, asset revaluation reserve, free reserve, fixed asset.

PROPOSED simplifications to review with the accountant:
- weekly snapshots at BS days 7, 14, 21 and 28 of the month;
- "large cooperative" uses current books (the standard says the previous year's audited figures);
- specific provisions are deducted from 100%-weighted assets as one balance;
- supplementary capital = general provision (≤ 1.5% of RWA) + free reserve, capped at core capital;
- core capital uses the Act's primary capital plus loss compensation fund and accumulated profit
  (register S-04 conflict).

PLANNED: export to the NCRA electronic system / goAML formats (formats not yet obtained),
quarterly snapshots stored for audit, foreclosed-asset Table 3.1, annual Schedule-3 AML report.
