# Members and KYM (Phase 4, part 1)

Status: 2026-10-16. Natural-person members only. Rules and sources: `regulatory-register.md`
sections 2 (M-rows) and 10b (K-rows).

## Flow — IMPLEMENTED (tested)

1. **Apply** (`member.apply`): `POST /api/members` with identity, Act s.30/s.32 declarations and
   the full KYM form (Schedule-1) under `kym`. Checks: age 16+, declarations, at least one of
   citizenship / national ID / passport number, no current applicant or member with the same
   number in this cooperative (citizenship number is matched together with its issuing district).
2. **Documents** (`member.apply`): `POST /api/members/{id}/documents` (`type`, `file`; JPEG/PNG/PDF,
   max 5 MB, content-sniffed). Required before approval: photo, right and left thumbprint, and
   one ID copy.
3. **Risk** (`member.risk_assess`): `POST /api/members/{id}/risk` `{risk_level, reason}`.
   A PEP (self or family, declared on the form) is high risk automatically and cannot be lowered.
4. **Decide** (`member.decide`): `approve` assigns the next member number and the next KYM review
   date (1/2/3 years for high/medium/low). `reject` requires a reason (Act s.31).
   - The person who entered the application cannot decide it (PROPOSED control).
   - PEP or high-risk approval needs `member.decide_high_risk` (Instruction 3(3)).
   - Two active members must recommend the applicant once the cooperative has two members.
5. **Update KYM** (`member.kyc_update`): `PUT /api/members/{id}/kyc` writes a new version and
   counts as a review.
6. **Review due**: `GET /api/members?kyc_review_due=1`.

The 35-day decision deadline is shown as `days_to_decide` (negative = overdue).

## Data protection — IMPLEMENTED (tested)

- The KYM form (ID numbers, family, income, addresses) is stored **encrypted** in
  `member_kyc_versions.payload`, one append-only row per change (triggers block edits/deletes).
- `members` keeps only what lists need, plus **keyed hashes** of ID numbers for duplicate checks.
- Member list/detail responses never include ID numbers. The decrypted form
  (`GET /api/members/{id}/kyc`) and document downloads need `member.kyc_view`, and **every view
  is written to the audit log**.
- Documents are stored on the private disk under `cooperatives/{id}/members/{id}/<random>.ext`,
  downloaded only through the API (`nosniff`, `no-store`), and are append-only.

## ⚠ Key management (production)

`APP_KEY` encrypts the KYM data and keys the duplicate-check hashes. **If it is lost, member
identity data cannot be decrypted.** Back it up securely and separately from database backups.
Rotating it requires re-encrypting `member_kyc_versions` and recomputing the hashes — no tool
for that exists yet.

## Frontend — IMPLEMENTED (tested)

`/members` (list, filters incl. "KYM review due"), `/members/new` (Schedule-1 in nine steps with
review, field-level server errors jump to their step, BS or AD dates), `/members/[id]` (tabs: details,
documents with required-document checklist and audited preview, shares, savings, loans, audited KYM
view, risk and approve/reject with confirmation), `/members/[id]/kym` (KYM review: new version). Buttons follow the permissions returned by `/api/auth/cooperative`; the API enforces them.

Forms are `method="post"` and their submit button stays disabled until React has hydrated, so a
click or Enter before the JavaScript loads can never do a native GET that would put passwords or
ID numbers into the URL (found during browser testing, fixed and tested).

Checked end to end in headless Chrome: apply → upload 4 documents → assess risk → the same person
is refused → a second user approves (member number assigned) → decrypted KYM shown.

## Not built yet (PLANNED)

- Legal-person members (Schedule-2), member exit and share refund.
- National ID system integration (Instruction 1(1)(घ)).
- Sanctions screening, PEP list maintenance, TTR/STR and source-of-funds capture — these attach
  to transactions (shares, savings).
- Record-retention and legal-hold automation; annual Schedule-3 report.
- BS date entry in forms (dates are entered in AD today; BS is shown where available).
