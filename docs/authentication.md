# Authentication and Session Security

Status: Phase 1 Steps 2–3, 2026-10-16. Sections are marked **IMPLEMENTED (tested)** or **PLANNED**.

## 1. Architecture decision

**Chosen:** Laravel's built-in session guards (cookie sessions) behind a Next.js
Backend-for-Frontend proxy. **No Sanctum, no extra packages.**

```
Browser ──(same origin, :3000)──> Next.js ──rewrite /api/*──> nginx ──> Laravel (web middleware: session + CSRF)
         HttpOnly session cookie          server-side proxy             auth guards: web | platform
         XSRF-TOKEN cookie
```

Why:
- The browser only talks to the Next.js origin. Next.js proxies `/api/*` to Laravel, so
  Laravel's cookies are first-party and same-origin. No CORS, no cross-site cookies.
- Laravel's session guard already provides what we need: password check with a timebox
  (unknown e-mails take as long as wrong passwords), session-ID rotation on login,
  HttpOnly cookies, CSRF protection, and `auth.session` (ends other sessions after a
  password change).
- **Sanctum was evaluated and not installed.** Its SPA mode would add exactly the
  `web` middleware stack to API routes, which we can do directly. Its other feature,
  API tokens, is not needed: no bearer token is ever given to browser JavaScript.
  Add Sanctum later only if a mobile app or third-party API client needs tokens.
- No tokens in `localStorage`. The only cookie JavaScript can read is `XSRF-TOKEN`,
  which is a CSRF token, not a credential.

## 2. Two separate identity spaces — IMPLEMENTED (tested)

| | Cooperative staff | Platform administrators |
|---|---|---|
| Table | `users` | `platform_users` |
| Guard | `web` | `platform` |
| Routes | `/api/auth/*` | `/api/platform/auth/*` |
| Protected by | `auth:web`, `auth.session`, `account.can-sign-in` | `auth:platform`, `auth.session`, `account.can-sign-in` |
| May sign in when | ≥ 1 active membership, at an active branch, of a cooperative that is `active` or `licence_suspended` | `status = active` |

- Each guard has its own provider and its own session key, so a staff session never
  satisfies `auth:platform` and vice versa — even when the e-mail or numeric ID is the same.
- Signing in to one guard signs the browser out of the other: one identity per session.
- Eligibility is re-checked on **every** authenticated request. Disabling an account or
  membership ends existing sessions on their next request.
- Signing in proves identity only. It does **not** select or authorize a cooperative.
  `cooperative_id`/`branch_id` from the client are never used for authorization.
  Choosing a cooperative is a separate step (§2a).
- Decision to confirm: staff of a `licence_suspended` cooperative can still sign in
  (to view records). Blocking transactions there is a separate, PLANNED rule.

## 2a. Tenant context — IMPLEMENTED (tested)

1. After login the client lists `GET /api/auth/cooperatives` (only eligible memberships) and
   chooses one with `PUT /api/auth/cooperative {cooperative_id}`. The server checks the
   membership, rotates the session ID and stores the cooperative ID **in the session**.
   "Not yours" and "does not exist" both get the same 403.
2. Cooperative-scoped routes use the `tenant` middleware. On **every** request it re-checks
   the stored choice against the user's eligible memberships, then sets `App\Tenancy\TenantContext`
   (cooperative + home branch) for that request only and clears it afterwards.
   - no choice yet → 409 `Select a cooperative first.`
   - membership disabled / cooperative closed since → 403 and the choice is cleared
   - no eligible membership at all → 401 (signed out by `account.can-sign-in`)
3. Models with `cooperative_id` use the `BelongsToTenant` trait:
   - every query is limited to the current cooperative; another tenant's row is a 404;
   - **fail closed:** querying with no tenant context throws `MissingTenantContext`
     instead of returning every cooperative's rows;
   - deliberate cross-tenant reads (seeders, future platform-admin tools) must use
     `TenantContext::withoutTenantScope()`, which is easy to find in review;
   - creating a row for a different cooperative than the current one throws.
4. The tenant middleware runs before route-model binding (middleware priority), so
   `{branch}` route parameters are always resolved inside the tenant scope.
5. Request input such as `?cooperative_id=`, `branch_id` or an `X-Cooperative-Id` header is
   ignored (tested).

First consumer: `GET /api/branches`, `GET /api/branches/{branch}` (read-only).
PLANNED: queued jobs must carry `cooperative_id` and set the context; platform-admin tenant access.

## 3. Endpoints — IMPLEMENTED (tested)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/csrf-cookie` | 204; sets `XSRF-TOKEN` for clients that need a token |
| POST | `/api/auth/login` | `{email, password}` → 200 `{data:{id,name,email}}` or 422 generic message |
| GET | `/api/auth/me` | 200 or 401 |
| POST | `/api/auth/logout` | 204; session invalidated, CSRF token rotated |
| POST | `/api/auth/forgot-password` | always the same 200 message |
| POST | `/api/auth/reset-password` | `{token, email, password, password_confirmation}` |
| GET | `/api/auth/cooperatives` | staff: eligible memberships |
| GET | `/api/auth/cooperative` | staff: current choice or `null` |
| PUT | `/api/auth/cooperative` | staff: `{cooperative_id}` → 200 or 403 |
| GET | `/api/branches`, `/api/branches/{id}` | staff + tenant: current cooperative's branches |
| POST | `/api/platform/auth/login` | same contract as staff login |
| GET | `/api/platform/auth/me` | |
| POST | `/api/platform/auth/logout` | |

Unauthenticated requests get `401 {"message":"Unauthenticated."}` (never a redirect).

## 4. Protections — IMPLEMENTED (tested unless noted)

- **Account enumeration:** same 422 message for unknown e-mail, wrong password and
  ineligible account; Laravel's timebox evens out response time (timing not measured in tests).
  Forgot-password always returns the same message.
- **Brute force:** `throttle:login` — 5/min per path+e-mail+IP and 20/min per path+IP.
  Password reset: 5/min per IP, plus the broker's 60 s per-e-mail throttle.
- **IP spoofing:** `TRUSTED_PROXIES` is empty by default, so `X-Forwarded-For` is ignored
  (tested). See §6 before changing it.
- **Session fixation:** session ID rotated on login (tested); session invalidated on logout.
- **Stolen session after password reset:** `auth.session` ends sessions created with the old
  password (tested). Reset rotates `remember_token`. "Remember me" is not offered.
- **CSRF:** Laravel's `PreventRequestForgery`. Accepts browser requests with
  `Sec-Fetch-Site: same-origin`, or a valid `X-XSRF-TOKEN`; rejects cross-site POSTs (419) (tested).
- **Cookies:** session cookie HttpOnly, SameSite=Lax, host-only (tested: HttpOnly + Lax).
- **Response hygiene:** responses use an explicit field list; password and remember token are
  hidden; reset tokens never appear in responses and are stored hashed (tested).
- **Password policy:** minimum 12 characters on reset (PROPOSED value — confirm with customer).
  Hashing: Laravel default bcrypt.

## 4a. Frontend screens — IMPLEMENTED (tested)

| Route | Behaviour |
|---|---|
| `/login` | Staff sign-in. Shows the API's generic error or "Too many attempts. Try again in N seconds." After sign-in, a single membership is selected automatically; otherwise → `/select-cooperative`. |
| `/select-cooperative` | Lists eligible cooperatives; choosing one calls `PUT /api/auth/cooperative`. |
| `/` | Staff home: current cooperative, home branch, branch list, Switch cooperative, Sign out. |
| `/status` | API health check (unauthenticated). |

- Pages are client components calling `/api/*` through the same-origin proxy (`src/lib/api.ts`).
  Laravel decides access; pages only follow its answers (401 → `/login`; 409/403 → `/select-cooperative`).
- The session cookie is never visible to JavaScript (verified in headless Chrome: `document.cookie`
  contains only `XSRF-TOKEN`). The client also sends `X-XSRF-TOKEN` for browsers without `Sec-Fetch-Site`.
- Platform-admin screens are not built.

Browser check (headless Chrome, synthetic seed account), production build and dev server:
signed-out `/` → `/login`; wrong password → generic error; sign-in → home with cooperative and
branches; switch cooperative; sign out → `/login`; `/` afterwards → `/login`.
Dev-mode note: the first visit to a not-yet-compiled route can make the dev server cancel in-flight
requests ("Could not reach the server"); reload once. Not seen with the production build.

## 5. Local environment variables (`backend/.env`, values are local-only)

| Variable | Local value | Purpose |
|---|---|---|
| `FRONTEND_URL` | `http://localhost:3000` | Base of reset-password links |
| `SESSION_DRIVER` | `database` | Server-side sessions; needs the `sessions` table |
| `SESSION_SECURE_COOKIE` | `false` | Local HTTP only |
| `SESSION_SAME_SITE` | `lax` | |
| `TRUSTED_PROXIES` | *(empty)* | Trust no forwarded headers |
| `CACHE_STORE` | `redis` | Shared rate-limit counters |
| `MAIL_MAILER` | `log` | Local only — writes reset links to the log |

Frontend: `API_INTERNAL_URL` (server-only, set in `docker-compose.yml` to `http://nginx`).

**Local runtime status:** the development database is migrated and seeded with synthetic data
(`php artisan db:seed`; refuses to run outside local/testing). Local sign-in: staff
`staff@demo.test`, platform admin `ops@demo.test`, password `local-dev-password` — local only.
Verified through `localhost:3000` with curl: login, cooperative selection, branches, logout,
cross-site POST rejected (419).

## 6. Production requirements — PLANNED, not done

1. HTTPS everywhere; `SESSION_SECURE_COOKIE=true`; consider `SESSION_COOKIE=__Host-sahakarios_session`.
2. **Forwarded IPs:** put an edge proxy/load balancer you control in front of Next.js that
   **overwrites** (not appends) `X-Forwarded-For` with the real client IP. Only then set
   `TRUSTED_PROXIES` to the internal addresses of Next.js and nginx. The Next.js proxy passes a
   client-supplied `X-Forwarded-For` through unchanged (verified locally), so trusting it without
   such an edge lets attackers dodge throttling.
3. Laravel/nginx must not be reachable from the internet directly — only via Next.js.
4. Real mailer (SMTP or provider) and a `/reset-password` page in the frontend. **E-mail delivery
   is not configured or tested.** Never use `MAIL_MAILER=log` in production.
5. Build the frontend with the production `API_INTERNAL_URL` (rewrites are fixed at build time).
6. Decide session lifetime (currently Laravel default 120 min idle) and password policy.
7. Platform administrators: MFA, provisioning command and their own password reset — not built.
8. Distributed password guessing (many IPs, one account) is only limited per IP today;
   consider a per-account limit or alerting once real traffic patterns are known.
9. Frontend: no password-reset page yet (`/reset-password`), no idle-timeout warning.
