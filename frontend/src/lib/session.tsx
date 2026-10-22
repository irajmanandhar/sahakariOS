'use client';

import { useRouter } from 'next/navigation';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, redirectForAuthError } from './api';
import type { BusinessDay, Membership, User } from './types';

/**
 * Who is signed in, for which cooperative, with which permissions, on which business day.
 * Loaded once per page load by the staff layout. Permissions only hide UI; the API enforces them.
 */
export type Session = {
  user: User;
  membership: Membership;
  businessDay: BusinessDay | null;
  can: (permission: string) => boolean;
  /** Read-only users (auditors): no permission that changes anything. */
  readOnly: boolean;
  setBusinessDay: (day: BusinessDay | null) => void;
};

export const SessionContext = createContext<Session | null>(null);

export function useSession(): Session {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession() outside <SessionProvider>');
  return session;
}

/** Permissions that only read. Anyone holding nothing else is shown the auditor view. */
const READ_ONLY = new Set(['audit_log.view', 'ledger.view', 'member.view', 'member.kyc_view', 'share.view', 'aml.view', 'savings.view', 'loan.view', 'report.view', 'notification.view']);

export function buildSession(user: User, membership: Membership, businessDay: BusinessDay | null, setBusinessDay: (d: BusinessDay | null) => void): Session {
  const permissions = new Set(membership.permissions ?? []);
  return {
    user,
    membership,
    businessDay,
    can: (p) => permissions.has(p),
    readOnly: [...permissions].every((p) => READ_ONLY.has(p)),
    setBusinessDay,
  };
}

type State = { status: 'loading' } | { status: 'error'; error: unknown } | { status: 'ready'; user: User; membership: Membership; businessDay: BusinessDay | null };

export function SessionProvider({ children, fallback }: { children: ReactNode; fallback: (state: { error?: unknown; retry: () => void }) => ReactNode }) {
  const router = useRouter();
  const [state, setState] = useState<State>({ status: 'loading' });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let live = true;
    (async () => {
      const [{ data: user }, { data: membership }] = await Promise.all([
        api<{ data: User }>('/auth/me'),
        api<{ data: Membership | null }>('/auth/cooperative'),
      ]);
      if (membership === null) {
        router.replace('/select-cooperative');
        return;
      }
      const { data: businessDay } = await api<{ data: BusinessDay | null }>('/business-day');
      if (live) setState({ status: 'ready', user, membership, businessDay });
    })().catch((error) => {
      if (live && !redirectForAuthError(error, router.replace)) setState({ status: 'error', error });
    });
    return () => {
      live = false;
    };
  }, [router, attempt]);

  const setBusinessDay = useCallback((businessDay: BusinessDay | null) => setState((s) => (s.status === 'ready' ? { ...s, businessDay } : s)), []);
  const session = useMemo(
    () => (state.status === 'ready' ? buildSession(state.user, state.membership, state.businessDay, setBusinessDay) : null),
    [state, setBusinessDay],
  );
  if (!session) {
    return fallback({ error: state.status === 'error' ? state.error : undefined, retry: () => { setState({ status: 'loading' }); setAttempt((a) => a + 1); } });
  }
  return <SessionContext.Provider value={session}>{children}</SessionContext.Provider>;
}
