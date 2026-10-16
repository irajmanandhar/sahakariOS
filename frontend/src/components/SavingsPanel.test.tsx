import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SavingsPanel from './SavingsPanel';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('SavingsPanel', () => {
  it('deposits with an idempotency key and shows server refusals', async () => {
    const account = { id: 3, account_number: 1, product: { id: 1, code: 'GEN', name: 'General', type: 'general' }, status: 'active', balance: '100.00', maturity_date: null };
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url === '/api/members/9/savings-accounts') return json({ data: [account] });
      if (url === '/api/savings-products') return json({ data: [] });
      if (url === '/api/savings-accounts/3') return json({ data: { transactions: [] } });
      if (url === '/api/savings-accounts/3/deposits') {
        return JSON.parse(String(init?.body)).amount === '999999999'
          ? json({ message: "This deposit would bring the member's savings to Rs 999999999.00; the limit is 10% of primary capital" }, 422)
          : json({ data: {} }, 201);
      }
      return json({}, 500);
    });
    vi.stubGlobal('fetch', fetchMock);
    render(<SavingsPanel memberId={9} permissions={['savings.view', 'savings.deposit']} active />);

    fireEvent.click(await screen.findByRole('button', { name: 'Select' }));
    fireEvent.change(await screen.findByLabelText('Amount (Rs)'), { target: { value: '500.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cash deposit' }));
    expect(await screen.findByText('Deposit posted.')).toBeTruthy();

    const call = fetchMock.mock.calls.find(([u]) => u === '/api/savings-accounts/3/deposits')!;
    expect((call[1] as RequestInit & { headers: Record<string, string> }).headers['Idempotency-Key']).toMatch(/^sav-/);

    fireEvent.change(screen.getByLabelText('Amount (Rs)'), { target: { value: '999999999' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cash deposit' }));
    expect(await screen.findByText(/the limit is 10% of primary capital/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Cash withdrawal' })).toBeNull(); // no permission
  });
});
