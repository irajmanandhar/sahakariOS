# Frontend

Status: 2026-10-22. Next.js 16 App Router, React 19, MUI 9, TypeScript. The frontend has no business
rules of its own. The API decides everything. Missing endpoints are listed in
[`frontend-gaps.md`](frontend-gaps.md).

## Structure

```
frontend/
  src/app/
    layout.tsx                 root: fonts, theme (light/dark), toasts
    login, forgot-password, reset-password, select-cooperative, status, platform/   signed-out screens
    (staff)/layout.tsx         session + AppShell for every cooperative screen (URLs have no "(staff)")
    (staff)/page.tsx           dashboard
    (staff)/members, savings, loans, shares, ledger, reports, aml, audit, banking, settings/
  src/components/
    shell/                     AppShell (sidebar, top bar, user menu), nav.ts (IA + permissions),
                               GlobalSearch, Shortcuts, IdleTimeout
    DataTable.tsx              the table for every list
    MoneyText, BsDate, StatusChip, PageHeader, ReportHeader, ConfirmDialog, States, Toasts, fields, AuthCard
    members/kym.tsx            Schedule-1 KYM form as data (apply + KYM update)
  src/lib/
    api.ts                     BFF client (cookies, X-XSRF-TOKEN, Idempotency-Key, errors)
    session.tsx                user, cooperative, permissions, business day
    hooks.ts                   useApi, useCursorList, useSubmit, useUnsavedChanges
    money.ts                   decimal-string money (BigInt paisa), Nepali grouping and digits
    bs.ts, bs-calendar.ts      AD↔BS from the verified table (tested against the backend file)
    ledger.ts, audit.ts        journal totals, account tree, audit filters and sequence check
    rules.ts                   prompt thresholds mirrored from the API (see frontend-gaps.md §4)
    gaps.ts                    stand-ins for missing endpoints
    types.ts                   API response shapes
  src/i18n/                    en.ts (keys), ne.ts (must translate every key), useT()
  src/test/render.tsx          renderWithSession, serveApi helpers
  e2e/                         Playwright: flow.spec.ts (end to end), a11y.spec.ts (axe)
```

## Conventions

- **Security model (unchanged).** The browser talks only to Next.js. `/api/*` is rewritten to Laravel
  (`API_INTERNAL_URL`, fixed at build time). The session cookie is HttpOnly, so JavaScript never sees a
  token. `api()` sends `X-XSRF-TOKEN`. A 401 sends the user to `/login`, a 409 to
  `/select-cooperative`, and a 403 shows the API's message.
- **Permissions** come from `useSession().can('…')` and only hide UI. Every action is enforced by the API.
  A user who holds only read permissions sees the "Auditor view: read-only" banner.
- **Money** is a decimal string from the API to the screen and back. `<MoneyText>` formats it
  (1,00,000.00, optional Nepali numerals). `parseMoneyInput()` turns typed text into the API string.
  Arithmetic (journal balance, totals, bar widths) uses BigInt paisa (`money.ts`). Never use `Number()`
  on money.
- **Dates.** `<BsDate>` shows BS with AD alongside. Timestamps are shown as the Nepal date and time.
  `<DateField>` takes BS (default) or AD input and always returns AD for the API. Outside the verified
  calendar (BS 2000–2083) the UI shows AD only and refuses BS input.
- **Forms.** Every form is `method="post"` with a `<SubmitButton>` that stays disabled until hydration.
  `useSubmit(prefix, action)` provides:
  - double-submit protection;
  - Laravel 422 `fieldErrors` (show them with `fieldError(errors, 'path')`);
  - one Idempotency-Key per attempt: kept for a retry after a network error or 5xx, replaced after a
    success or a 4xx.

  `useUnsavedChanges(dirty)` guards reloads and sidebar links. Money is never updated optimistically.
- **Irreversible actions** (post, reverse, approve, reject, disburse, close the day, import) go through
  `<ConfirmDialog>`. Reversals and rejections require a written reason. Dialog errors stay in the dialog.
- **Text.** Every label is a key in `src/i18n/en.ts` with its translation in `ne.ts`; the type checker
  rejects a missing key. API messages are shown as sent. Users pick the language and Nepali numerals in
  the account menu; the choice is stored per browser in localStorage.
- **Accessibility.** There is one `h1` per page (`PageHeader`) and a skip link. Focus moves to `main` on
  navigation. Dialogs use MUI focus management. Status always has an icon and words, never colour alone.
  Tooltips describe rather than rename (`describeChild`). Tables become card lists below 600px. Reduced
  motion is respected. axe runs in `e2e/a11y.spec.ts`.
- **Long lists.** `DataTable` virtualises above 150 rows (`@tanstack/react-virtual`). Next splits code
  per route automatically. Staff pages are client components because they need the cookie session
  through the BFF; signed-out pages are static.

## How to add a screen

1. Create `src/app/(staff)/<area>/page.tsx` (`'use client'`). If it reads `useParams`/`useSearchParams`,
   use a server `page.tsx` that renders the client view inside `<Suspense>` (see `ledger/journal/[id]`).
2. Load data with `useApi<T>('/path')` or, for cursor lists, `useCursorList<T>('/path')`. Add the response
   shape to `lib/types.ts`.
3. Render `<PageHeader title crumbs subtitle actions />`, then a `<DataTable>` for lists. Show errors
   with `<ErrorState error onRetry />`.
4. For actions, use `useSubmit` + `<ConfirmDialog>`, then `toast.success(...)`.
5. Add the text keys to `en.ts` and `ne.ts`. The type checker reports a key missing from either.
6. Add the route to `components/shell/nav.ts` with its permission.
7. Test with `renderWithSession(<Page />, { permissions })` and `serveApi({...})`. If it is a main
   screen, add it to `e2e/a11y.spec.ts`.
8. If the API lacks something, add a row to `frontend-gaps.md` and use `<GapNotice gap="Gn">`.

## Commands

```sh
cd frontend
npm run lint && npx tsc --noEmit && npm test && npm run build
# End to end (stack running, db:seed done). This writes synthetic data to the local DB:
npm run build && npx next start -p 3100 &
E2E_BASE_URL=http://localhost:3100 PW_CHANNEL=chrome npm run e2e   # or: npx playwright install chromium
```

The `web` container runs `next dev` on a Windows bind mount, which may not notice new files. Restart it
(`docker compose restart web`) or test against `next start` as above.

## Production checklist (frontend)

- `API_INTERNAL_URL`: build with the production value. Rewrites are fixed at build time, and the
  address is never sent to the browser.
- `NEXT_PUBLIC_SESSION_IDLE_MINUTES`: set it to the API's `SESSION_LIFETIME` (gap G11).
- Serve over HTTPS only. HSTS is sent by production builds.
- Put an edge proxy in front that overwrites `X-Forwarded-For` (see `authentication.md` §6).
- CSP is set in `next.config.ts`. It allows `'unsafe-inline'` for scripts (Next's bootstrap) and styles
  (Emotion). To tighten it, add `proxy.ts` that issues a nonce per request, pass the nonce to
  `InitColorSchemeScript` and the Emotion cache, and remove `'unsafe-inline'` from `script-src`.
- Error tracking: report from `app/(staff)/error.tsx` and `app/global-error.tsx` (both `console.error` today).
- Run `npm audit`, and run the Playwright suite against staging before each release.
- Have cooperative staff review the Nepali copy (`ne.ts`).
