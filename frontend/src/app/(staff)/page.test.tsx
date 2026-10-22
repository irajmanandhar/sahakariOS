import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { json, renderWithSession, serveApi, testDay } from '@/test/render';
import DashboardPage from './page';

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router, usePathname: () => '/' }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const report = (sections: unknown[], checks: unknown[] = []) => ({ data: { code: 'x', title: 'x', sections, checks } });

describe('DashboardPage', () => {
  it('hides business-day actions without permission', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({})));
    renderWithSession(<DashboardPage />);

    expect(await screen.findByText('No business day is open')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /open today/i })).toBeNull();
  });

  it('opens the business day after confirmation', async () => {
    const fetchMock = vi.fn(serveApi({ 'POST /api/business-day/open': () => json({ data: testDay }, 201) }));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<DashboardPage />, { permissions: ['business_day.open'] });

    fireEvent.click(screen.getByRole('button', { name: /open today/i }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: /open today/i }));

    expect(await screen.findByText('30 Asoj 2083')).toBeTruthy();
    expect(fetchMock.mock.calls.some(([u, i]) => u === '/api/business-day/open' && i?.method === 'POST')).toBe(true);
  });

  it('shows the server reason when opening is refused, inside the dialog', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({ 'POST /api/business-day/open': () => json({ message: 'No open fiscal year covers this date.' }, 422) })));
    renderWithSession(<DashboardPage />, { permissions: ['business_day.open'] });

    fireEvent.click(screen.getByRole('button', { name: /open today/i }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: /open today/i }));

    expect(await within(screen.getByRole('dialog')).findByText('No open fiscal year covers this date.')).toBeTruthy();
  });

  it('asks for confirmation before closing the day', async () => {
    const fetchMock = vi.fn(serveApi({ 'POST /api/business-day/close': () => json({ data: { ...testDay, status: 'closed' } }) }));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<DashboardPage />, { permissions: ['business_day.close'], businessDay: testDay });

    fireEvent.click(screen.getByRole('button', { name: /close business day/i }));
    expect(within(await screen.findByRole('dialog')).getByText(/cannot be undone/)).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /close business day/i }));

    expect(await screen.findByText('No business day is open')).toBeTruthy();
  });

  it('shows key figures from the reports, amounts as strings', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({
      '/api/reports/statements/portfolio_ratios': report([{ title: 'Savings', rows: [{ label: 'Total savings', amount: '1234567.50' }] }, { title: 'Loans', rows: [{ label: 'Total outstanding', amount: '250000.00' }] }]),
      '/api/reports/statements/liquidity': report([], [{ label: 'Liquid assets / savings', value: '18.20%', requirement: '≥ 15%', ok: true, source: 'NCRA Std 4/1' }]),
      '/api/reports/statements/loan_classification': report([{ title: 'Loans', rows: [{ label: 'Pass', amount: '250000.00', note: '2 loan(s)' }], total: '250000.00' }]),
    })));
    renderWithSession(<DashboardPage />, { permissions: ['report.view'], businessDay: testDay });

    expect(await screen.findByText('Rs 12,34,567.50')).toBeTruthy();
    expect(screen.getByText('18.20%')).toBeTruthy();
    expect(screen.getByText('Meets requirement')).toBeTruthy();
    expect((await screen.findAllByText('2,50,000.00')).length).toBeGreaterThan(1);
  });
});
