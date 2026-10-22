import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { json } from '@/test/render';
import { SessionProvider, useSession } from './session';

const replace = vi.fn();
const router = { replace, push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

beforeEach(() => replace.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function Probe() {
  const s = useSession();
  return <p>{s.membership.cooperative.name} {s.can('member.view') ? 'can view' : 'cannot view'} {s.readOnly ? 'read-only' : 'can change'}</p>;
}

const tree = <SessionProvider fallback={({ error }) => <p>{error ? 'failed' : 'loading'}</p>}><Probe /></SessionProvider>;

describe('SessionProvider', () => {
  it('sends signed-out visitors to login', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ message: 'Unauthenticated.' }, 401)));
    await act(async () => { render(tree); });
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/login'));
  });

  it('asks for a cooperative when none is selected', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => (url === '/api/auth/me' ? json({ data: { id: 1, name: 'S', email: 's@x.test' } }) : json({ data: null }))));
    await act(async () => { render(tree); });
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/select-cooperative'));
  });

  it('provides permissions and detects a read-only (auditor) role', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url === '/api/auth/me') return json({ data: { id: 1, name: 'A', email: 'a@x.test' } });
      if (url === '/api/auth/cooperative') return json({ data: { cooperative: { id: 1, code: 'C', name: 'Demo Coop', status: 'active' }, branch: { id: 1, code: 'HO', name: 'HO' }, permissions: ['member.view', 'audit_log.view'] } });
      return json({ data: null });
    }));
    await act(async () => { render(tree); });
    expect(await screen.findByText('Demo Coop can view read-only')).toBeTruthy();
  });

  it('shows a retryable error when the server cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
    vi.spyOn(console, 'error').mockImplementation(() => {});
    await act(async () => { render(tree); });
    expect(await screen.findByText('failed')).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });
});
