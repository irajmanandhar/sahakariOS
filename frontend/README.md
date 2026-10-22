# SahakariOS frontend

Next.js 16 (App Router) · React 19 · MUI 9 · TypeScript. Structure, conventions, how to add a screen
and the production checklist: [`../docs/frontend.md`](../docs/frontend.md). Missing endpoints:
[`../docs/frontend-gaps.md`](../docs/frontend-gaps.md).

```sh
npm ci
npm run dev                 # http://localhost:3000 (API via API_INTERNAL_URL, default http://localhost:8080)
npm run lint && npx tsc --noEmit && npm test && npm run build
npm run e2e                 # Playwright against a running, seeded local stack (see docs/frontend.md)
```
