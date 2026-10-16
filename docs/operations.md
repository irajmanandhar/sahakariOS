# Operations

Status: 2026-10-16. Local Docker environment; production deployment has **not** been set up.

## Services (`docker-compose.yml`)
mysql 8.4, redis 8.2, api (php-fpm 8.4), nginx (API on :8080), web (Next.js on :3000),
scheduler (`php artisan schedule:work`: runs `sms:send` every minute).

## Integrity commands — run daily and after every restore
| Command | Checks |
|---|---|
| `php artisan ledger:verify` | every journal entry complete and balanced; reversals mirror originals; savings balances = general ledger |
| `php artisan audit:verify` | audit hash chains unbroken (no edited, deleted or missing rows) |

## Day-to-day
- Open the business day (home page or `POST /api/business-day/open`), transact, close the day.
  Closing runs savings interest accrual/posting, loan classification, and loan-loss provisioning
  on BS month ends.
- `php artisan sms:send` delivers the SMS outbox (scheduled). Driver: `SMS_DRIVER` (`log` only;
  a provider still has to be chosen and integrated).

## Pilot migration (cut-over)
1. Agree the cut-over date; open that business day.
2. Create the chart of accounts, ledger roles (incl. `opening_balance`) and products.
3. `php artisan migration:import <COOP> members.csv --dry-run`, fix every reported row, then run
   without `--dry-run`. Header:
   `member_number,full_name,date_of_birth,gender,phone,membership_date,share_units,savings_product_code,savings_balance`.
4. Post the rest of the old trial balance (cash, banks, loans, reserves, …) by manual journal
   against the opening-balance account until it is **0.00**.
5. Run `ledger:verify`; compare the trial balance with the old system's; sign off with the accountant.
6. Imported members have their KYM review due at once: complete their Schedule-1 forms.
Loans are **not** imported yet (their schedules need a separate import tool).

## Backups and restore (to set up for production — PLANNED)
- Nightly full MySQL backup + binary logs for point-in-time recovery; encrypted, off-site.
- Back up `APP_KEY` separately and securely: it decrypts member KYM data (see `members.md`).
- Back up `storage/app/private` (member documents).
- Monthly restore drill into an isolated environment, followed by `ledger:verify` and
  `audit:verify`. Targets proposed: RPO ≤ 15 min, RTO ≤ 4 h.
- Never run `migrate:fresh`, `db:wipe` or delete volumes on a real database.

## Production checklist (not done)
HTTPS and secure cookies; edge proxy that overwrites `X-Forwarded-For`, then `TRUSTED_PROXIES`;
API not reachable directly; real mailer; SMS provider; `log_bin_trust_function_creators=1` on
MySQL (audit/ledger triggers); data-residency decision; BS 2084 calendar before 2027-04-13;
accountant sign-off on every PROPOSED treatment (`accounting.md`, `savings.md`, `loans.md`, `reports.md`).
