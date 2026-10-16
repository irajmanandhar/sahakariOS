import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import HomePage from './page';

const replace = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace, push: vi.fn() }) }));

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const membership = { cooperative: { id: 1, code: 'DEMO', name: 'Demo Cooperative', status: 'active' }, branch: { id: 1, code: 'HO', name: 'Head Office' } };

beforeEach(() => replace.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('HomePage', () => {
  it('sends signed-out visitors to login', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ message: 'Unauthenticated.' }, 401)));
    render(<HomePage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/login'));
  });

  it('asks for a cooperative when none is selected', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(json({ data: { id: 1, name: 'S', email: 's@x.test' } }))
      .mockResolvedValueOnce(json({ data: null })));
    render(<HomePage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/select-cooperative'));
  });

  it('shows the current cooperative and its branches', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(json({ data: { id: 1, name: 'Demo Staff', email: 's@x.test' } }))
      .mockResolvedValueOnce(json({ data: membership }))
      .mockResolvedValueOnce(json({ data: [{ id: 1, code: 'HO', name: 'Head Office', is_head_office: true, status: 'active' }] }))
      .mockResolvedValueOnce(json({ data: { date: '2026-10-16', date_bs: '2083-06-30', date_bs_text: '30 Asoj 2083', fiscal_year: '2083/84', status: 'open' } })));
    render(<HomePage />);

    expect(await screen.findByText('Demo Cooperative')).toBeTruthy();
    expect(screen.getByText(/Business date: 30 Asoj 2083 BS \(2026-10-16\)/)).toBeTruthy();
    expect(screen.getByText('Head Office (head office)')).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });
});
