# Moving from Excel passbooks to SahakariOS

Many cooperatives keep every member's passbook in one large Excel workbook: one sheet per product
(ऐच्छिक, नियमित, दैनिक, मुद्दती, शेयर, ऋण …), one block of columns per account, one row per day.
SahakariOS can take that history over, so the books start from the old opening balances and every
old passbook row stays traceable, instead of starting from a single cut-over figure.

There are two ways in:

| Route | Use when |
|---|---|
| **A. Convert the old workbook** (`migration:convert-excel`) | The cooperative uses the standard passbook workbook layout described below. |
| **B. Fill the standard template** (`history.csv`) | Any other source: another software's export, a different Excel layout, or hand-prepared data. |

Both routes end in the same two imports: `migration:import` (members) and
`migration:import-history` (backdated passbook rows, "backdated bulk upload").

> Member files contain personal and financial data. Keep them out of Git (`/imports/` and all
> spreadsheet files are ignored), on the office machine only, and delete them after sign-off.

## The standard template (route B)

`history.csv`, UTF-8 (Excel: *Save as → CSV UTF-8*), one row per passbook entry:

| Column | Meaning |
|---|---|
| `date_ad` | AD date `YYYY-MM-DD`. May be blank if `date_bs` is given. |
| `date_bs` | BS date `YYYY-MM-DD` (optional). If both are given they must be the same day. |
| `voucher_no` | Old voucher number (optional, max 30). Shown in the passbook. |
| `member_number` | Must already exist (import members first). |
| `product_code` | A savings product code, or `SHARE` for the share register. |
| `legacy_account_no` | The old passbook's account number (savings; required). |
| `type` | `opening` (brought forward), `deposit`, `withdrawal`, `interest`, `tax`, `charge`. Shares: `opening`, `deposit` (purchase), `withdrawal` (refund). |
| `amount` | Positive, at most 2 decimals. Shares in whole Rs 100 shares. |
| `particulars` | Free text (max 300), Nepali or English. |

Optional `expected_balances.csv` (`product_code,legacy_account_no,member_number,balance`): the old
system's final balance for every account. With `--expected`, the import is refused unless every
account ends on exactly that balance.

## Route A: the old passbook workbook

```sh
mkdir -p imports
docker compose run --rm --no-deps -v "$PWD/<workbook>.xlsx:/data/in.xlsx:ro" -v "$PWD/imports:/data/out" \
  api php -d memory_limit=3G artisan migration:convert-excel /data/in.xlsx /data/out --as-of=YYYY-MM-DD
```

It reads the workbook (it never writes to the database) and produces in `imports/`:

| File | What it is |
|---|---|
| `members.csv` | From the *Member List* sheet, for `migration:import`. Date of birth and gender are not in the workbook: they stay blank and are collected at the KYM review. |
| `history.csv` | Every typed Debit/Credit amount up to `--as-of`, with the brought-forward balance as an `opening` row. |
| `expected_balances.csv` | The Balance column of each passbook at `--as-of`, for `--expected`. |
| `products.csv` | One line per sheet: suggested product code, account type, heading interest rate, minimum balance (from the *Can Withdraw* formula). Create these products before importing. |
| `loans.csv` | Loan sheet figures at `--as-of` (disbursed, repaid, outstanding principal, interest, fine). **Reference only**: loans are set up in the Loans module; this file is what to check them against. |
| `findings.txt` | What an auditor should look at (below). |

Layout it understands: row 5 account type, row 7 `A/c No.<n> … Member No.<m>`, row 8
`Interest Rate:<r>%`, the *अगाडिको मौज्दात* (brought forward) row, then one row per day with
Nepali date, Day, English date, Voucher No., Particulars, Debit, Credit, Can Withdraw, Balance,
Interest, Tax (12 columns per account; loan sheets 17). Text typed in the Preeti font is
converted to Unicode Nepali.

How rows are classified (check them in `history.csv` before importing):
- Credit with *ब्याज*/Interest in particulars or voucher → `interest`; other credits → `deposit`;
- Debit with *कर*/Tax → `tax`; with Charge/Renew/*शुल्क* → `charge`; other debits → `withdrawal`.

### What `findings.txt` reports

- **Interest rate differs from the passbook heading** (e.g. heading 6%, daily formula 3%).
- **Balance column differs from brought forward + credits − debits**: a broken formula or a typing error.
- **Loan interest on interest**: the loan sheet's interest formula is applied to principal **plus unpaid
  interest and fine**. Cooperatives Act 2074 s.50(5) forbids capitalising interest; SahakariOS charges
  interest on principal only, so loan figures will differ and the difference needs a board/accountant decision.
- Nepali and English dates on the same row that are not the same day (the English date is used).
- Amounts on rows without a date, after `--as-of`, negative amounts, amounts with more than 2 decimals.
- Member List problems: duplicate member numbers, cancelled (*खारेज*) members, missing names or dates,
  passbooks whose member is not in the list.

## Running the import

1. Set up the cooperative: fiscal year, chart of accounts, ledger roles (`cash`, `opening_balance`,
   `share_capital`, `savings_interest_expense`, `interest_tax_payable`, `service_charge_income`) and the
   savings products (codes as in `products.csv`, or edit `history.csv`). **Do not open a business day**
   before the first history date: history is replayed day by day and business days only move forward.
2. `php artisan migration:import <COOP> members.csv --dry-run`, fix, then run it for real.
3. `php artisan migration:import-history <COOP> history.csv --expected=expected_balances.csv --dry-run`,
   fix every reported row, then run it for real. For each date it opens the business day, posts that
   day's rows (same journal entries as the counter would), and closes the day.
4. Post the rest of the old trial balance (cash, bank, loans, reserves …) against the opening balance
   account until it is 0.00; set up the loans from `loans.csv`; run `ledger:verify`.
5. Sign off: the auditor compares the passbooks (member page → Savings → Passbook, or *Download CSV*)
   and the **Day book by product** report with the old workbook.

PROPOSED (needs the accountant): interest and tax rows are taken as the old system calculated them; the
app's own daily interest starts the day after the last imported date. Historical cash over the AML
thresholds (deposits ≥ Rs 10 lakh, withdrawals > Rs 5 lakh) is imported as recorded and listed for review.

## Audit views in the app

| Old workbook | SahakariOS |
|---|---|
| A member's passbook block | Member → Savings → *Select* → Passbook: BS and AD date, voucher, particulars, debit, credit, can withdraw, balance; *Download CSV* (each export is audit-logged). |
| *Balance* sheet (daily totals per product) | Reports → *Day book by product*: credit, debit and closing balance per product per day, brought forward and closing. |
| Interest/Tax columns | Interest is accrued daily and posted at the product's posting dates; the passbook shows interest accrued but not yet posted. |
| Formulas that can be overwritten | Posted entries cannot be changed (database triggers); corrections are reversals; `ledger:verify` and `audit:verify` prove the books and the audit trail are intact. |

Not done yet: loan history import, uploading the workbook through the web screen (it is a command
run by the implementer, because the files are large and hold member data), and the bank, expense,
income and remittance sheets (their totals go in with the opening trial balance).
