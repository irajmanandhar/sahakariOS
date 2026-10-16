import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import HomePage from './page';

const replace = vi.fn();
// Stable object, like Next's real router (a new object per render would re-run effects).
const router = { replace, push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const membership = { cooperative: { id: 1, code: 'DEMO', name: 'Demo Cooperative', status: 'active' }, branch: { id: 1, code: 'HO', name: 'Head Office' } };

beforeEach(() => replace.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const day = { date: '2026-10-16', date_bs: '2083-06-30', date_bs_text: '30 Asoj 2083', fiscal_year: '2083/84', status: 'open' };

function mockHome(permissions: string[], businessDay: unknown) {
  const fetchMock = vi.fn()
    .mockResolvedValueOnce(json({ data: { id: 1, name: 'Demo Staff', email: 's@x.test' } }))
    .mockResolvedValueOnce(json({ data: { ...membership, permissions } }))
    .mockResolvedValueOnce(json({ data: [] }))
    .mockResolvedValueOnce(json({ data: businessDay }));
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('HomePage', () => {
  it('hides business-day actions without permission', async () => {
    mockHome([], null);
    render(<HomePage />);

    expect(await screen.findByText('No business day is open.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /open today/i })).toBeNull();
  });

  it('opens the business day when permitted', async () => {
    const fetchMock = mockHome(['business_day.open'], null);
    fetchMock.mockResolvedValueOnce(json({ data: day }, 201));
    render(<HomePage />);

    fireEvent.click(await screen.findByRole('button', { name: /open today/i }));

    expect(await screen.findByText(/Business date: 30 Asoj 2083 BS/)).toBeTruthy();
    expect(fetchMock.mock.calls[4][0]).toBe('/api/business-day/open');
  });

  it('shows the server reason when opening is refused', async () => {
    const fetchMock = mockHome(['business_day.open'], null);
    fetchMock.mockResolvedValueOnce(json({ message: 'No open fiscal year covers this date.' }, 422));
    render(<HomePage />);

    fireEvent.click(await screen.findByRole('button', { name: /open today/i }));

    expect(await screen.findByText('No open fiscal year covers this date.')).toBeTruthy();
  });

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
