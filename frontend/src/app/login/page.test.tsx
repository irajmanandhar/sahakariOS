import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LoginPage from './page';

const replace = vi.fn();
// Stable object, like Next's real router (a new object per render would re-run effects).
const router = { replace, push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

const json = (body: unknown, status = 200, headers: Record<string, string> = {}) =>
  new Response(JSON.stringify(body), { status, headers });

function submit(email = 'staff@example.test', password = 'secret-password') {
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: email } });
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: password } });
  fireEvent.click(screen.getByRole('button', { name: /sign in/i }));
}

beforeEach(() => replace.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('LoginPage', () => {
  it('shows the server message on bad credentials and stays on the page', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      json({ message: 'x', errors: { email: ['These credentials do not match our records.'] } }, 422),
    ));
    render(<LoginPage />);

    submit();

    expect(await screen.findByText('These credentials do not match our records.')).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });

  it('explains throttling with the retry time', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ message: 'Too Many Attempts.' }, 429, { 'Retry-After': '30' })));
    render(<LoginPage />);

    submit();

    expect(await screen.findByText('Too many attempts. Try again in 30 seconds.')).toBeTruthy();
  });

  it('signs in and opens the only cooperative', async () => {
    const membership = { cooperative: { id: 1, code: 'DEMO', name: 'Demo', status: 'active' }, branch: { id: 1, code: 'HO', name: 'HO' } };
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(json({ data: { id: 1, name: 'Demo Staff', email: 'staff@example.test' } }))
      .mockResolvedValueOnce(json({ data: [membership] }))
      .mockResolvedValueOnce(json({ data: membership }));
    vi.stubGlobal('fetch', fetchMock);
    render(<LoginPage />);

    submit('staff@example.test', 'secret-password');

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
    expect(fetchMock.mock.calls[0][1].body).toBe('{"email":"staff@example.test","password":"secret-password"}');
  });
});
