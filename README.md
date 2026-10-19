# SahakariOS

Cloud core-banking and accounting platform for Nepali savings and credit cooperatives.

**Status: MVP feature-complete for local evaluation (not deployed).** Multi-tenant staff app with
session authentication, per-cooperative roles, tamper-evident audit log, BS calendar, double-entry
ledger, members with encrypted KYM, shares, savings (with interest), loans (with repayment,
classification and provisioning), statements and NCRA returns, bank reconciliation, SMS outbox
and a pilot import. Accounting treatments are **PROPOSED** until a qualified cooperative
accountant signs them off.

Docs: [`architecture.md`](docs/architecture.md) · [`regulatory-register.md`](docs/regulatory-register.md) ·
[`authentication.md`](docs/authentication.md) · [`members.md`](docs/members.md) ·
[`accounting.md`](docs/accounting.md) · [`savings.md`](docs/savings.md) · [`loans.md`](docs/loans.md) ·
[`reports.md`](docs/reports.md) · [`operations.md`](docs/operations.md) · [`migration.md`](docs/migration.md)


## Layout

| Path | Purpose |
|---|---|
| `backend/` | Laravel 13 REST API (PHP 8.4) |
| `frontend/` | Next.js 16 App Router + React 19 + MUI 9 (TypeScript) |
| `docker/` | Dockerfile and config for local services |
| `docker-compose.yml` | Local development stack |
| `.github/workflows/ci.yml` | CI: backend lint + tests, frontend lint + typecheck + tests + build |
| `docs/` | Architecture and regulatory register |

## Local services

| Service | Image | Reachable at |
|---|---|---|
| `mysql` | mysql:8.4.11 | containers only (`mysql:3306`) |
| `redis` | redis:8.2.10-alpine | containers only (`redis:6379`) |
| `api` | built from `docker/php/Dockerfile` (php:8.4.26-fpm-alpine) | via nginx |
| `nginx` | nginx:1.28.3-alpine | http://localhost:8080 |
| `web` | node:22.23.3-alpine | http://localhost:3000 |

Startup order is enforced by healthchecks: mysql + redis healthy → api → nginx (`/api/health`
must return 200) → web.

## First run

```sh
cp backend/.env.example backend/.env          # local-only dummy credentials
(cd frontend && npm ci)                       # installs frontend deps (mounted into the web container)
docker compose up -d --build                  # build PHP image, start everything
docker compose exec api composer install      # only needed if backend/vendor is missing
docker compose exec api php artisan key:generate
docker compose ps                             # all services should show (healthy)
curl http://localhost:8080/api/health         # {"status":"ok","checks":{"database":"ok","redis":"ok"}}
```

## Sign in locally

```sh
docker compose exec api php artisan migrate      # local dev database only
docker compose exec api php artisan db:seed      # synthetic demo data; refuses non-local environments
```
Open http://localhost:3000 and sign in as `staff@demo.test` or `approver@demo.test`, password
`local-dev-password` (local only). Use the second user to approve what the first entered.

## Tests

```sh
docker compose exec api php artisan test      # backend, uses the separate sahakarios_test database
docker compose exec api ./vendor/bin/pint --test
cd frontend && npm test && npm run lint && npx tsc --noEmit && npm run build
```

`docker/mysql/init/01-test-database.sql` creates `sahakarios_test` the first time the MySQL volume
is created. If you created the volume before that file existed, create the database manually
rather than deleting the volume.

## Safety rules

- All credentials in this repo are throwaway local values. Never commit real `.env` files.
- No destructive database commands (`migrate:fresh`, `db:wipe`, volume deletion) without explicit approval.
