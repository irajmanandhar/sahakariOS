# Frontend gaps: missing endpoints and open decisions

Status: 2026-10-22. The frontend was built against the API as it is (`backend/routes/api.php`). It does
not change the API. Where a screen needs something the API does not offer, the UI shows a
**"Waiting for the API"** notice (`<GapNotice gap="Gn">`), and any stand-in lives in
`frontend/src/lib/gaps.ts`. A stand-in only uses endpoints that already exist and says what it cannot do.
It never invents data or behaviour.

## Missing endpoints

| Id | Needed for | Wanted | Today in the UI |
|---|---|---|---|
| G1 | Audit log viewer | `GET /api/audit-logs` filters (`actor_id`, `action`, `subject_type`, `subject_id`, `from`, `to`); `hash` and `prev_hash` in each row; actor names | Filters run on the rows loaded so far ("Load more" fetches older pages). Sequence numbers are checked for gaps in the browser. Hashes are verified only by `audit:verify`. |
| G2 | Dashboard integrity panel; Audit → Integrity | `GET /api/integrity` with the latest `ledger:verify` and `audit:verify` result (time, ok, findings) | Notice. The integrity page shows what the API can show: whether the trial balance balances, and gaps in the audit sequence. |
| G3 | Drill-down: report figure → journal lines → source | `GET /api/ledger-accounts/{id}/lines?from&to` (account ledger); `source_type`/`source_id` on journal entries; filters on `GET /api/journal-entries` (date, account, source); `account_id` on report rows | Report rows that name an account open the account page (balance from the trial balance). The account's lines and each entry's source show a notice. |
| G4 | Dashboard member count | Member count / summary endpoint | "—" with a notice. |
| G5 | Global member search; AML risk review | `GET /api/members?search=` (number or name); `risk_level` / `is_pep` filters | The newest 200 members are loaded and filtered in the browser (`searchMembers`, `recentMembers`). The limit is shown in the UI. |
| G6 | KYM history | `GET /api/members/{id}/kyc/versions` | The latest version only (versions are kept server-side). |
| G7 | Savings accounts across members; share register | `GET /api/savings-accounts`; `GET /api/shares` (holdings per member) | Accounts are reached from the member page. Share capital totals are in the day book and the balance sheet. |
| G8 | Platform administration | `/api/platform/cooperatives` (list, create, suspend), support-access grants | Platform sign-in and a "signed in as" page. Cooperatives are created with `cooperative:create`. |
| G9 | Role and user administration | Roles CRUD, permission assignment, users and memberships | Shows the signed-in user's own permissions. |
| G10 | Branches | `POST/PUT /api/branches` | Read-only branch list. |
| G11 | Idle timeout | Session lifetime in `/api/auth/me` (or a header) | The build sets `NEXT_PUBLIC_SESSION_IDLE_MINUTES` (default 120, Laravel's default). |
| G12 | Bilingual API messages | Nepali validation and rule messages (architecture §10) | API messages are shown in English as sent. |
| G13 | Actor and poster names | User names next to `posted_by`, `actor_id` | Shown as "User #id". |

## Open decisions

1. **Nepali copy review.** `frontend/src/i18n/ne.ts` is a draft. Cooperative staff should review it before
   go-live, especially the regulatory terms.
2. **403 handling.** `docs/authentication.md` lists "409/403 → /select-cooperative". The code only
   redirects on 409. A 403 shows the API's message, because it usually means "your role does not allow
   this". When a membership is disabled, the server clears the selection, so the next request returns
   409 and redirects anyway.
3. **Client CSV exports.** Ledger, report, audit, loan and product tables can be exported as CSV in the
   browser, and those exports are not audit-logged. Member lists have no export. The passbook CSV comes
   from the API and is audit-logged. Decide whether more exports must go through the API.
4. **Rule values shown in the UI.** `frontend/src/lib/rules.ts` copies thresholds such as Rs 10 lakh for
   source of funds, Rs 5 lakh for large cash and share face value Rs 100. They only decide when a prompt
   appears; the API enforces the rules. An endpoint exposing the cooperative's parameters would remove
   the copy.
5. **Manual-journal maker-checker** is PROPOSED in `accounting.md` and has no API yet. The UI says so.
6. **CSP.** `script-src 'unsafe-inline'` (see `docs/frontend.md`, production checklist).
7. **E2E in CI.** The Playwright suite needs the full docker stack and a seeded database, so it is not in
   CI yet.
