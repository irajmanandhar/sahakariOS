# SahakariOS — Architecture

Status: draft v0.1, 2026-10-16 (2083 Asoj 30 BS). Approved as a direction for Phase 0 only.

Regulatory facts are kept in [`regulatory-register.md`](regulatory-register.md) with their
verification status. This document refers to them; it does not restate them as fact.

Labels used:
- **PROPOSED** — design choice or accounting treatment awaiting professional validation.
- **VERIFIED / UNVERIFIED / CONFLICT** — only used in the regulatory register.

## 1. Product requirements (initial customer)

Users: board/manager, accountant, cashier/teller, loan officer, member-service staff, audit committee
(read-only), external auditor (read-only, time-boxed), SaaS platform staff (separate).

Must do, day one of go-live:
1. Register members with KYC, one-member-one-cooperative self-declaration, family/relative links.
2. Sell/refund membership shares (Rs 100 face value), with share register.
3. Run savings products (general, regular, term ≤ 3 years) and post deposits/withdrawals at the counter.
4. Run loan products: application → appraisal → approval → disbursement **into member's own savings
   account** → schedule → repayment → overdue classification → provisioning.
5. Keep a full double-entry general ledger; every operational transaction posts a balanced journal.
6. Cashier: teller sessions, vault, cash count, bank accounts and bank reconciliation.
7. Produce trial balance, balance sheet, P&L, member statements, and the NCRA regulatory returns
   (loan classification/provision, foreclosed assets, liquidity, capital adequacy, interest-rate report).
8. Enforce regulatory limits as **hard blocks or approval-required warnings** (configurable parameters,
   values from the verified register below — never hard-coded).
9. Maker-checker approvals, immutable audit trail, day close.
10. Bikram Sambat (BS) dates in the UI and reports; Nepali fiscal year (Shrawan 1 – Asar end).
11. Nepali and English UI labels; Nepali digits optional in print.

Non-functional: no data loss, no cross-tenant leakage, every report number traceable to journal lines,
works on modest office internet, printable receipts/passbook pages.

## 2. MVP scope and what is postponed

**MVP (go-live for customer 1):** modules 1–13 in reduced form: platform admin (create cooperative,
suspend), cooperative config, users/roles, members + shares, savings, deposits/withdrawals, loans
(one or two product types), repayment, GL + core reports, teller + bank, regulatory returns,
audit trail + approvals, day close, SMS notification of transactions (one provider).

**Postponed (designed for, not built):**
- Member portal / mobile app, online payments (wallet/ConnectIPS), QR deposits.
- Multiple branches UI and inter-branch accounting (schema carries `branch_id` from day one).
- Multiple cooperative customers onboarding self-service, billing/subscriptions.
- Collective-guarantee (group) lending, project loans with staged disbursement, rescheduling workflow
  (rule exists but needs Authority standard per special situation), loan write-off.
- Fixed asset register, payroll, budgeting, inventory (prohibited trading anyway).
- Direct electronic submission to NCRA/Department MIS/CIC/FIU goAML (export files first; integrate when
  their formats/APIs are obtained).
- Dividend and protected-capital-return distribution engine (year-end; build before first FY close).

> Section 3 (regulatory research) lives in [`regulatory-register.md`](regulatory-register.md).

## 4. System architecture and module boundaries

```
Browser (Next.js App Router, React, TS, MUI)  ── HTTPS ──>  nginx
   │  BFF route handlers keep session cookie (httpOnly);       │
   │  browser never holds API tokens                            ▼
   └──────────────────────────────────────────────>  Laravel API (php-fpm)
                                                       │  ├─ MySQL 8.4 LTS (InnoDB, utf8mb4)
                                                       │  ├─ Redis (queues, cache, rate limit, locks)
                                                       │  └─ Object storage (S3-compatible; KYC docs)
                                                     queue worker(s) + scheduler (same image)
```

- **Modular monolith** in Laravel. One deployable, one database. Modules are folders with clear
  public services; modules call each other only through those services, never each other's tables.
  Reason: one team, one customer — microservices would add failure modes with no benefit.
- Repo layout (monorepo): `backend/` (Laravel), `frontend/` (Next.js), `docker/`, `docs/`, `.github/workflows/`.
- Backend modules (`backend/app/Modules/<Name>`): Platform, Tenancy, Identity (users/roles), Members,
  Shares, Savings, Loans, Accounting (GL engine — the only writer of journals), Cashier, Approvals,
  Audit, Reporting, Notifications, Calendar (BS/AD, fiscal years, business date).
- Rule: **every money movement = one domain operation = one DB transaction** that writes the domain
  record + the journal entry + audit record, or nothing.
- Frontend: Next.js server components for read pages, client components for forms; MUI; API calls via
  a server-side BFF so the Laravel session/token stays in an httpOnly cookie. Money is passed as
  **decimal strings**, never JS numbers.
- Versions: latest stable Laravel, Next.js, MUI at Phase 0, pinned in lockfiles.

## 5. Database schema (core, abbreviated)

Conventions: `BIGINT UNSIGNED` ids; every tenant table has `cooperative_id` NOT NULL; transactional
tables also `branch_id`; money `DECIMAL(18,2)`; rates `DECIMAL(9,6)`; accrual working amounts
`DECIMAL(20,6)`; `created_by`, timestamps; AD dates stored, BS derived. Composite unique
`(cooperative_id, id)` on parents and composite FKs `(cooperative_id, parent_id)` on children so the
**database itself refuses cross-tenant references**.

Platform: `platform_users`, `cooperatives` (status: active / licence_suspended / closed, licence no.,
working area, local-level class), `branches`, `cooperative_settings` (versioned parameters with
effective dates and source reference).

Identity: `users` (cooperative staff), `cooperative_user` (membership + branch), `roles`,
`permissions`, `role_user`; `personal_access/sessions`; `login_audits`.

Calendar: `bs_calendar` (BS year/month day counts, verified data), `fiscal_years`, `accounting_periods`
(open/closed), `business_days` (per tenant business date, day open/close).

Members: `members` (member no., KYC fields, status, joined_on), `member_documents`, `member_relations`
(family/relative), `member_kyc_reviews`.

Shares: `share_transactions` (purchase/refund/transfer; links journal_entry_id), view `share_balances`.

Savings: `savings_products` (type general/regular/term, rate, calc method, posting frequency, min
balance, term max, versioned), `savings_accounts` (member, product, status, balance, hold_amount,
`version`), `savings_transactions` (type, amount, running balance, journal_entry_id, reversal_of_id),
`savings_interest_accruals`, `account_holds` (loan lien).

Loans: `loan_products`, `loan_applications`, `loan_collaterals`, `loan_guarantors`, `loan_approvals`,
`loans` (principal, rate, disbursed, outstanding, classification, status, `version`),
`loan_schedules` (instalment rows), `loan_transactions` (disbursement/repayment/charge with allocation
breakdown), `loan_interest_accruals`, `loan_classification_history`, `loan_provisions`,
`foreclosed_assets`.

Accounting: `ledger_accounts` (chart of accounts tree, type asset/liability/equity/income/expense,
control-account flag, normal side), `journal_entries` (entry no. per FY, entry_date, value_date,
status draft/posted, source_type/source_id, reversal_of_id, idempotency_key, posted_by, approved_by),
`journal_lines` (ledger_account_id, debit, credit, member/subledger reference, branch_id),
`ledger_balances` (per account per day, maintained in the same transaction).

Cashier: `tills`, `teller_sessions` (open float, closing count, variance), `cash_movements`,
`bank_accounts`, `bank_statement_lines`, `bank_reconciliations`.

Control: `approval_policies` (operation, threshold, roles), `approval_requests`, `idempotency_keys`,
`audit_logs` (append-only, hash chained), `notifications`, `report_runs` (snapshot + parameters + hash).

## 6. Tenant isolation and authorization

> Authentication as built (Phase 1 Step 2) is documented in [`authentication.md`](authentication.md).

- Single database, shared schema, `cooperative_id` column. Simplest model that scales to hundreds of
  cooperatives; per-tenant DB can come later for a large customer.
- **Tenant comes from the authenticated user's server-side session**, never from a request body/header.
  A user linked to several cooperatives picks one; the server checks `cooperative_user` and stores the
  choice in the session. Any id in a URL is loaded through tenant-scoped queries ⇒ foreign ids give 404.
- Enforcement layers: (1) middleware sets `TenantContext`; (2) Eloquent global scope + creating hook
  on a `BelongsToTenant` trait; (3) composite FKs in MySQL; (4) policies per action; (5) tests that try
  every endpoint with another tenant's ids (automated cross-tenant test suite).
- Queued jobs carry `cooperative_id` explicitly and re-establish `TenantContext`; jobs without it fail.
- Files: object keys prefixed `coop/{id}/`, served only through an authorized download endpoint with
  short-lived signed URLs.
- Reports and exports run through the same scoped services.
- Platform admins: separate table, separate guard, separate route prefix (`/platform`), MFA required.
  They do **not** see financial data by default; support access needs a time-boxed, audited grant by the
  cooperative.
- Authorization: RBAC with fine-grained permissions (`savings.deposit.create`, `loan.approve`, …),
  segregation of duties (maker ≠ checker), amount limits per role, branch scoping.
- Security baseline: Argon2id passwords, MFA (TOTP) for privileged roles, rate-limited login, session
  timeout, CSRF, strict CORS, security headers, encrypted KYC fields where needed, TLS everywhere,
  secrets via env not repo, dependency scanning in CI.

## 7. Accounting engine design

> As built (Phase 3): [`accounting.md`](accounting.md). The text below is the original design.

> **PROPOSED — every accounting treatment, rounding rule, day-count and allocation order in
> sections 7–9 requires validation by a qualified cooperative accountant before go-live.**
> Nothing here is a regulatory requirement unless it cites the regulatory register.

- One service, `Accounting\PostingService::post(JournalDraft)`, is the **only** code that writes
  `journal_entries`/`journal_lines`. Domain modules build a draft from a **posting rule** (template:
  e.g. "savings deposit cash" ⇒ Dr Cash-in-hand, Cr Member savings – product X) configured per tenant
  and reviewed by the accountant.
- Posting checks inside the DB transaction: sum(debit) = sum(credit) > 0; every line one-sided and
  positive; accounts active, postable (leaf) and same tenant; period open; business date valid;
  idempotency key unused.
- **Posted entries are immutable.** App never updates/deletes them; MySQL triggers `SIGNAL` on
  UPDATE/DELETE of posted lines (defence in depth). Corrections = reversal entry (mirror lines,
  `reversal_of_id`) + new correct entry, both approved.
- Money arithmetic with `brick/math` BigDecimal; never floats. Rounding: compute at 6 dp, round to
  **paisa (2 dp)** at posting with a **per-tenant configurable rounding mode, default HALF_UP pending
  accountant approval**; rounding differences posted to a designated account, never lost.
- Sub-ledgers (savings, loans, shares) each tie to a GL control account; nightly reconciliation job
  asserts sum(subledger) = control balance and raises an alert otherwise.
- Period close: closing a month/FY locks postings; FY close transfers P/L via controlled entries;
  appropriation of reserve/protected-capital/promotion funds as approved by general assembly.
- Chart of accounts: we ship a **draft template** for the accountant to adapt; it is not presented as
  regulatory. Regulator format to be obtained (gap 3.3).
- **PROPOSED treatments (need accountant validation):** savings = liability per account; accrued
  savings interest = expense/payable; loan interest **accrued in memo (interest receivable vs interest
  suspense) and recognised as income on receipt** with the Shrawan 15 rule handled by a FY-end
  adjustment, consistent with D1/D2; provision = expense vs loan loss provision (contra asset); shares =
  equity.

## 8. Savings transaction lifecycle

1. Product configured (rates from board decision, effective-dated) → account opened for active member
   (KYC complete) → status active.
2. Deposit: teller request with `Idempotency-Key` → checks (member active, product limits, per-member
   10% of primary capital, deposit > Rs 10 lakh needs source of funds, teller session open, licence not
   suspended) → if over approval threshold ⇒ pending approval → post: lock account row (`SELECT … FOR
   UPDATE`), insert savings_transaction, update balance, post journal (Dr Cash / Cr Savings), audit, receipt.
3. Withdrawal: same flow; available = balance − holds − minimum balance; net of member's overdue
   liabilities where required; Dr Savings / Cr Cash.
4. Transfer between own accounts / to loan repayment: single transaction, both accounts locked in id order.
5. Interest: daily accrual job per account (method per product) → periodic capitalisation posting
   (Dr Interest expense / Cr Savings) per product frequency. TDS: **not implemented until tax advice**.
6. Term deposit maturity, early closure penalty per product rules (board-approved).
7. Dormancy/closure; closure requires zero balance and no holds.
8. Error correction: reversal by checker; original stays visible.

## 9. Loan transaction lifecycle

1. Application (member eligibility: ≥ 3 months membership, ≥ 6 for unsecured, not director/CEO/family
   restrictions, single-member limit 15% of primary capital, product limits).
2. Appraisal: collateral (own/one-household family only, LTV caps, valuation by listed valuer above
   threshold), guarantors (≥ 2 for unsecured), CIC check above threshold (manual upload in MVP).
3. Approval per approval policy (committee/board), recorded with approver and reason.
4. Disbursement: **only to member's own savings account** — Dr Loan receivable / Cr Member savings;
   fees as allowed (Dr Savings / Cr Fee income). Generates schedule (EMI or equal principal per product;
   grace period rules).
5. Daily interest accrual on declining balance (actual days; day-count basis 365 — **to confirm**).
6. Repayment: allocation order (proposed: penalty → interest → principal, **to confirm**); penalty
   interest capped at 5% of overdue interest; no capitalisation of interest.
7. Daily/month-end classification by oldest overdue instalment; provision delta posted monthly or
   quarterly (frequency to confirm).
8. Closure; collateral release letter within 7 working days.
9. Postponed: rescheduling (D1 rules), foreclosure → foreclosed asset, write-off.

## 10. API structure

- REST, JSON, `/api/v1`. Tenant implicit from session. Resources: `/members`, `/members/{id}/shares`,
  `/savings-products`, `/savings-accounts/{id}/deposits|withdrawals|transfers|statement`,
  `/loan-products`, `/loan-applications/{id}/approve|reject`, `/loans/{id}/disburse|repayments|schedule`,
  `/ledger-accounts`, `/journal-entries` (manual journals, approval), `/journal-entries/{id}/reverse`,
  `/teller-sessions`, `/bank-accounts`, `/approvals`, `/reports/{code}`, `/audit-logs`; platform under
  `/platform/v1/...`.
- Money-moving POSTs require `Idempotency-Key`; replay returns the original response; same key with a
  different body ⇒ 422.
- Concurrency: optimistic `version` field on edits; pessimistic row locks inside posting.
- Errors: RFC 9457 problem+json; validation messages bilingual.
- OpenAPI spec generated and checked in CI; frontend types generated from it.

## 11. Automated testing strategy

- Backend: Pest/PHPUnit against **real MySQL in Docker** (not SQLite — locking/decimal behaviour differs).
- Accounting invariants: property-style tests — random sequences of operations ⇒ trial balance always
  balances; subledger = control; reversal nets to zero; immutability triggers reject updates.
- Concurrency tests: parallel withdrawals on one account never overdraw; duplicate idempotency keys post once.
- Tenant isolation suite: every route hit with other tenant's ids ⇒ 404/403; jobs without tenant fail.
- Regulatory rule tests: one table-driven test per verified rule (limits, classification, provision %),
  with parameters loaded from settings so a rule change is a data change + test update.
- Golden-file tests for reports against hand-calculated examples **approved by the accountant**.
- Frontend: Vitest + Testing Library for components, Playwright E2E for teller deposit, loan disbursement.
- CI (GitHub Actions): lint (Pint, ESLint), static analysis (Larastan), tests, OpenAPI diff, dependency audit.

## 12. Deployment, backup, recovery

- Docker images: `api` (php-fpm + nginx), `worker`, `scheduler`, `web` (Next.js), MySQL 8.4, Redis.
  Local: `docker compose`. Production target (later, with your approval): single VPS or managed VM in a
  region acceptable for Nepali data residency (**decision needed**), managed MySQL if available.
- Backups: nightly full + binlog for point-in-time recovery; encrypted, off-site copy; retention per
  regulation (pending Rules 2075) — proposed 30 daily / 12 monthly / yearly long-term.
- **Restore drill monthly** into an isolated environment, checked by trial balance + audit hash chain.
- Targets (proposal): RPO ≤ 15 min, RTO ≤ 4 h.
- Zero-downtime migrations rule: additive first, backfill, then remove; no destructive migrations
  without backup + approval.
- Monitoring: health checks, error tracking, queue depth, failed jobs, reconciliation alerts.

## 13. Implementation roadmap (small, verifiable phases)

| Phase | Deliverable | Done when |
|---|---|---|
| 0 | `git init`, monorepo skeleton, Docker compose (MySQL, Redis, api, web), Laravel + Next.js installed, `/api/health`, CI, docs saved | `docker compose up` works; health test + one frontend test pass in CI |
| 1 | Platform admins, cooperatives, branches, users, roles/permissions, tenant context, audit log — **done 2026-10-16** (role admin UI, maker-checker pending) | cross-tenant test suite passes |
| 2 | BS calendar + fiscal years + business day — **done 2026-10-16** (open/close day via HTTP waits for roles) | conversion tests vs verified table |
| 3 | Accounting engine: chart of accounts, posting service, reversal, immutability triggers, trial balance — **done 2026-10-16**, see [`accounting.md`](accounting.md) | invariant + concurrency tests pass |
| 4 | Members + KYC + documents | AML guidance read & mapped first |
| 5 | Shares | share register reconciles to GL |
| 6 | Savings products/accounts, teller sessions, deposits/withdrawals, approvals | E2E teller flow; overdraw race test |
| 7 | Savings interest accrual/capitalisation, day close | accountant-approved golden cases |
| 8 | Loan products, applications, approvals, disbursement, schedules | golden schedules approved |
| 9 | Repayments, accrual, classification, provisioning | regulatory tables 3.1/3.2 match manual calc |
| 10 | Bank accounts, reconciliation, financial statements, regulatory returns (4.1, 6.1/6.2, interest report) | traceability: each figure drills to lines |
| 11 | Notifications (SMS), pilot data migration, UAT with customer | sign-off |
| Later | FY close & profit appropriation, rescheduling, write-off, branches, member portal, multi-customer billing | — |

## 14. Open decisions (owner: product owner)

1. **Accountant/legal reviewer:** who validates the chart of accounts, posting rules, interest recognition
   (cash vs accrual), rounding, day-count, repayment allocation order? Phase 3 can be built, but go-live
   needs sign-off.
2. **Customer data:** does customer 1 have existing data (another software or Excel) to migrate? Which
   working area (metro / municipality / rural — affects tax and LTV)?
3. **Hosting & data residency:** host in Nepal (local data centre) or international cloud? Any customer
   or legal requirement?
4. **SMS provider** and whether member notifications are in MVP.
5. **Language:** Nepali-first UI, or English-first with Nepali labels?
6. **Authentication:** Laravel Sanctum session via Next.js BFF (recommended) acceptable?
7. **AML guidance A1 and Cooperative Rules 2075:** can you or the customer obtain the current
   official texts (the Law Commission repository was offline for me)?
8. Git: repository initialised locally (Phase 0). First commit and any remote wait for your approval.
