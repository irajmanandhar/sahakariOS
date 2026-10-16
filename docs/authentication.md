# Authentication and Session Security

Status: Phase 1 Step 2, 2026-10-16. Sections are marked **IMPLEMENTED (tested)** or **PLANNED**.

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
  Tenant context is the next step (PLANNED).
- Decision to confirm: staff of a `licence_suspended` cooperative can still sign in
  (to view records). Blocking transactions there is a separate, PLANNED rule.

## 3. Endpoints — IMPLEMENTED (tested)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/csrf-cookie` | 204; sets `XSRF-TOKEN` for clients that need a token |
| POST | `/api/auth/login` | `{email, password}` → 200 `{data:{id,name,email}}` or 422 generic message |
| GET | `/api/auth/me` | 200 or 401 |
| POST | `/api/auth/logout` | 204; session invalidated, CSRF token rotated |
| POST | `/api/auth/forgot-password` | always the same 200 message |
| POST | `/api/auth/reset-password` | `{token, email, password, password_confirmation}` |
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

**Local runtime status:** the development database has not been migrated (by instruction),
so login through `localhost:3000` currently returns 500 (`sessions` table missing). The proxy
itself is verified (`/api/health` through `:3000` → 200). All auth behaviour is verified by
the automated tests against `sahakarios_test`. Migrating the dev DB needs your approval.

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
9. Frontend login/logout screens are not built yet.
