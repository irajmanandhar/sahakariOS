import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LedgerPage from './page';

const replace = vi.fn();
const router = { replace, push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

beforeEach(() => replace.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('LedgerPage', () => {
  it('shows the trial balance and entries with amounts as given by the API', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(json({ data: {
        accounts: [{ account_id: 1, code: '1.1', name: 'Cash', type: 'asset', debit_balance: '1000.10', credit_balance: '0.00' },
                   { account_id: 2, code: '2.1', name: 'Savings', type: 'liability', debit_balance: '0.00', credit_balance: '1000.10' }],
        total_debit: '1000.10', total_credit: '1000.10', balanced: true } }))
      .mockResolvedValueOnce(json({ data: [{ id: 1, reference: '2083/84-000001', entry_date: '2026-10-16', entry_date_bs: '2083-06-30', description: 'Deposit', total: '1000.10', reversal_of_id: null }] })));
    render(<LedgerPage />);

    expect(await screen.findByText('Balanced')).toBeTruthy();
    expect(screen.getAllByText('1000.10').length).toBeGreaterThan(2);
    expect(screen.getByText('2083/84-000001')).toBeTruthy();
  });

  it('shows the reason when the role does not allow viewing the ledger', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => json({ message: 'This action is unauthorized.' }, 403)));
    render(<LedgerPage />);

    expect(await screen.findByText('This action is unauthorized.')).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });

  it('asks for a cooperative when none is selected', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => json({ message: 'Select a cooperative first.' }, 409)));
    render(<LedgerPage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/select-cooperative'));
  });
});
