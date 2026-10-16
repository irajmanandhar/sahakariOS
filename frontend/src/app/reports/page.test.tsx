import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ReportsPage from './page';

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router }));
const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200 });

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('ReportsPage', () => {
  it('renders sections and flags failed checks', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url === '/api/reports') return json({ data: [{ code: 'interest_spread', title: 'Interest rates and spread' }] });
      return json({ data: { code: 'interest_spread', title: 'Interest rates and spread', sections: [{ title: 'Weighted average rates', rows: [{ label: 'Spread', amount: '6.7000%' }] }],
        checks: [{ label: 'Loan rate − savings rate', value: '6.7000%', requirement: '≤ 6%', ok: false, source: 'Act s.50(4)' }] } });
    }));
    render(<ReportsPage />);
    await screen.findByLabelText('Report');
    fireEvent.click(screen.getByRole('button', { name: 'Show' }));

    expect(await screen.findByText('6.7000%', { selector: 'strong' })).toBeTruthy();
    expect(screen.getByRole('alert').className).toMatch(/Error|error/);
  });
});
