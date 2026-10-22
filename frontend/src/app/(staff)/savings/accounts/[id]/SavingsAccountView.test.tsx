import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { json, renderWithSession, serveApi, testDay } from '@/test/render';
import SavingsAccountView from './SavingsAccountView';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), push: vi.fn() }), useParams: () => ({ id: '3' }) }));

const account = {
  id: 3, account_number: 1, member_id: 9, product: { id: 1, code: 'GEN', name: 'General', type: 'general' }, status: 'active',
  balance: '700.00', hold_amount: '0.00', opened_on: '2026-10-16', maturity_date: null, legacy_account_number: '1001',
  minimum_balance: '500.00', accrued_interest: '1.25',
  transactions: [{ id: 1, date: '2026-10-18', date_bs: '2083-07-01', reference: 'V-9', journal_entry_number: 4, type: 'withdrawal', channel: 'cash', particulars: 'Cash', debit: '300.00', credit: null, amount: '300.00', balance: '700.00', can_withdraw: '200.00', narration: null }],
};

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('SavingsAccountView', () => {
  it('shows the passbook with BS dates, vouchers and the audited CSV download', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({ '/api/savings-accounts/3': { data: account } })));
    renderWithSession(<SavingsAccountView />, { permissions: ['savings.view'], businessDay: testDay });

    expect(await screen.findByText('2083-07-01')).toBeTruthy();
    expect(screen.getByText('V-9')).toBeTruthy();
    expect(screen.getByText('1001')).toBeTruthy();
    expect(screen.getByText('Rs 200.00')).toBeTruthy(); // available = balance 700 - hold 0 - minimum 500
    expect(screen.getByRole('link', { name: 'Download CSV' }).getAttribute('href')).toBe('/api/savings-accounts/3/passbook.csv');
    expect(screen.queryByRole('button', { name: 'Review deposit' })).toBeNull(); // no permission
  });

  it('asks for the source of funds from Rs 10 lakh and posts with an idempotency key', async () => {
    const fetchMock = vi.fn(serveApi({
      '/api/savings-accounts/3': { data: account },
      'POST /api/savings-accounts/3/deposits': () => json({ data: { transaction_id: 7, type: 'deposit', amount: '1000000.00', balance_after: '1000700.00', balance: '1000700.00', journal_entry_id: 11 } }, 201),
    }));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<SavingsAccountView />, { permissions: ['savings.view', 'savings.deposit'], businessDay: testDay });

    fireEvent.change(await screen.findByLabelText(/Amount \(Rs\)/), { target: { value: '10,00,000' } });
    expect((screen.getByRole('button', { name: 'Review deposit' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText(/Source of funds/), { target: { value: 'Sale of land' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review deposit' }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Post deposit' }));

    expect(await screen.findByText(/Balance after/)).toBeTruthy();
    const [, init] = fetchMock.mock.calls.find(([u]) => u === '/api/savings-accounts/3/deposits')!;
    expect((init as { headers: Record<string, string> }).headers['Idempotency-Key']).toMatch(/^sav-/);
    expect(JSON.parse(String(init!.body))).toMatchObject({ amount: '1000000.00', source_of_funds: 'Sale of land' });
  });

  it('asks for a written reason above Rs 5 lakh cash withdrawal and warns without the permission', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({ '/api/savings-accounts/3': { data: account } })));
    renderWithSession(<SavingsAccountView />, { permissions: ['savings.view', 'savings.withdraw'], businessDay: testDay });

    fireEvent.change(await screen.findByLabelText(/Amount \(Rs\)/), { target: { value: '500000.01' } });
    expect(screen.getByLabelText(/Reason for the large cash withdrawal/)).toBeTruthy();
    expect(screen.getByText(/savings.large_cash_withdrawal/)).toBeTruthy();
  });
});
