import { cleanup, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { json, renderWithSession } from '@/test/render';
import TrialBalancePage from './page';

const replace = vi.fn();
const router = { replace, push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

beforeEach(() => replace.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('TrialBalancePage', () => {
  it('shows the trial balance with amounts as given by the API, grouped', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ data: {
      as_of: null,
      accounts: [{ account_id: 1, code: '1.1', name: 'Cash', type: 'asset', debit_balance: '100000.10', credit_balance: '0.00' },
                 { account_id: 2, code: '2.1', name: 'Savings', type: 'liability', debit_balance: '0.00', credit_balance: '100000.10' }],
      total_debit: '100000.10', total_credit: '100000.10', balanced: true } })));
    renderWithSession(<TrialBalancePage />, { permissions: ['ledger.view'] });

    expect(await screen.findByText('Balanced')).toBeTruthy();
    expect(screen.getAllByText('1,00,000.10').length).toBeGreaterThan(2);
    expect(screen.getByRole('link', { name: '1.1' }).getAttribute('href')).toBe('/ledger/accounts/1');
  });

  it('shows the reason when the role does not allow viewing the ledger', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => json({ message: 'This action is unauthorized.' }, 403)));
    renderWithSession(<TrialBalancePage />);

    expect(await screen.findByText('This action is unauthorized.')).toBeTruthy();
    expect(replace).not.toHaveBeenCalled();
  });

  it('asks for a cooperative when none is selected', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => json({ message: 'Select a cooperative first.' }, 409)));
    renderWithSession(<TrialBalancePage />);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/select-cooperative'));
  });
});
