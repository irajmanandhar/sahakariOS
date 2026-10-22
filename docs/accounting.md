# Accounting Engine (Phase 3)

Status: 2026-10-16. **IMPLEMENTED (tested)** unless marked **PROPOSED** or **PLANNED**.
Nothing here is an accounting treatment for savings, loans or shares — those come with their
modules and need a qualified cooperative accountant's approval first.

## What the engine guarantees

| Rule | Enforced by |
|---|---|
| Every line is one-sided and positive | MySQL CHECK `journal_lines_one_sided_positive` + PostingService |
| Debits = credits = entry total; no lines added after posting | trigger `journal_lines_balance` (header stores `line_count`, `total`) + PostingService |
| Posted entries and lines never change or disappear | triggers `journal_entries_no_update/delete`, `journal_lines_no_update/delete` |
| Corrections only by reversal; at most one reversal; reversals are final | unique `reversal_of_id` + PostingService |
| Lines, accounts, branches, entries all belong to one cooperative | composite foreign keys + tenant scope |
| Postings only on the open business day, inside its fiscal year | PostingService locks the open `business_days` row |
| Sequential entry numbers per cooperative and fiscal year (`2083/84-000001`) | `journal_sequences` row lock + unique index |
| A retried request never posts twice (`Idempotency-Key`) | unique `(cooperative_id, idempotency_key)` + request hash |
| Exact money: 2 decimal places, no floats, no silent rounding | `App\Accounting\Money` (brick/math), DECIMAL(18,2), JSON strings |
| Every posting and reversal audited in the same transaction | AuditLogger (`journal.posted`, `journal.reversed`) |

Verified by `tests/Feature/LedgerTest.php` (including writes that bypass the application) and
`tests/Feature/ConcurrentPostingTest.php` (8 forked processes posting at the same moment).
`php artisan ledger:verify` re-checks every entry from the raw tables.

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/ledger-accounts` | `ledger.view` |
| POST | `/api/ledger-accounts` `{code, name, type, is_postable, parent_code?}` | `ledger_account.manage` |
| GET | `/api/journal-entries` (newest first) | `ledger.view` |
| POST | `/api/journal-entries` `{description, lines:[{account_code, debit|credit, description?}]}` + `Idempotency-Key` header | `journal.post` |
| GET | `/api/journal-entries/{id}` | `ledger.view` |
| POST | `/api/journal-entries/{id}/reverse` `{reason}` | `journal.reverse` |
| GET | `/api/reports/trial-balance?as_of=YYYY-MM-DD` | `ledger.view` |

Amounts must be JSON **strings** such as `"1250.50"`; numbers are refused. Rule violations return
422 with a readable message. Replaying an `Idempotency-Key` with the same body returns the
original entry (200); with a different body it is refused (422).

Frontend: `/ledger` (trial balance as of a date), `/ledger/journal` (entries, viewer with reversal chain
and "Reverse" with a required reason), `/ledger/journal/new` (manual journal, live exact debit = credit
check), `/ledger/accounts` (chart of accounts tree, create). Missing drill-down endpoints:
`frontend-gaps.md` G3.

## Decisions that need the cooperative's accountant — PROPOSED / open

1. **Chart of accounts.** SahakariOS ships none. The local seeder's `DEMO-*` accounts are
   synthetic. The regulator's format for financial statements (register R-09) is still to obtain.
2. **Accounting framework** (e.g. NFRS for SMEs) — register R-01, unverified.
3. **Manual journals** are posted by one permitted user today. Proposed: maker-checker
   (second person approves) above a threshold — **PLANNED**.
4. **Rounding** of computed amounts (interest etc.): the engine accepts only exact paisa amounts
   and never rounds; the rounding rule for calculations is open.
5. **Reversal date:** reversals post on the current business day, not the original date.
6. **Period close / fiscal-year close, opening balances, retained earnings transfer** — PLANNED.
7. **Subledger reconciliation** (savings, loans, shares vs control accounts) — PLANNED with those modules.
8. **Trial balance performance:** summed from lines on each request; balance snapshots PLANNED when volume needs it.
