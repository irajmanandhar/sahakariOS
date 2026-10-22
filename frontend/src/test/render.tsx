import { render } from '@testing-library/react';
import { type ReactElement, type ReactNode, useState } from 'react';
import { ToastProvider } from '@/components/Toasts';
import { buildSession, SessionContext } from '@/lib/session';
import type { BusinessDay } from '@/lib/types';

export const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

export const testDay: BusinessDay = { date: '2026-10-16', date_bs: '2083-06-30', date_bs_text: '30 Asoj 2083', fiscal_year: '2083/84', status: 'open' };

function Session({ permissions, businessDay, children }: { permissions: string[]; businessDay: BusinessDay | null; children: ReactNode }) {
  const [day, setDay] = useState(businessDay);
  const session = buildSession(
    { id: 1, name: 'Demo Staff', email: 'staff@demo.test' },
    { cooperative: { id: 1, code: 'DEMO', name: 'Demo Cooperative', status: 'active' }, branch: { id: 1, code: 'HO', name: 'Head Office' }, permissions },
    day,
    setDay,
  );
  return <ToastProvider><SessionContext.Provider value={session}>{children}</SessionContext.Provider></ToastProvider>;
}

/** Renders a staff page inside a signed-in session with the given permissions. */
export function renderWithSession(ui: ReactElement, { permissions = [], businessDay = null }: { permissions?: string[]; businessDay?: BusinessDay | null } = {}) {
  return render(<Session permissions={permissions} businessDay={businessDay}>{ui}</Session>);
}

/** fetch stub that answers by URL ("GET /api/x" or "/api/x"); unknown URLs get a 500. */
export function serveApi(routes: Record<string, unknown | ((init?: RequestInit) => Response)>) {
  return async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET';
    const route = routes[`${method} ${url}`] ?? routes[url];
    if (route === undefined) return json({ message: `unexpected ${method} ${url}` }, 500);
    return typeof route === 'function' ? (route as (i?: RequestInit) => Response)(init) : json(route);
  };
}
