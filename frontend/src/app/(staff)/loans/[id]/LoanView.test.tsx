import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { json, renderWithSession, serveApi } from '@/test/render';
import LoanView from './LoanView';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), push: vi.fn() }), useParams: () => ({ id: '5' }) }));

const loan = {
  id: 5, loan_number: 1, status: 'applied', member: { id: 9, member_number: 12, full_name: 'Sita Example' },
  product: { id: 1, code: 'SL', name: 'Savings-secured', collateral_type: 'savings' }, principal: '90000.00', interest_rate: '12.000000',
  term_months: 12, grace_months: 0, purpose: 'Goats', outstanding_principal: '0.00', classification: null, applied_on: '2026-10-16',
  disbursed_on: null, interest_paid_through: null, decision_reason: null, schedule: [], transactions: [],
};

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('LoanView', () => {
  it('hides decisions without permission', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({ '/api/loans/5': { data: loan }, '/api/loan-products': { data: [] } })));
    renderWithSession(<LoanView />, { permissions: ['loan.view'] });

    expect(await screen.findByText('Loan 1')).toBeTruthy();
    expect(screen.getByText('Rs 90,000.00')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
  });

  it('shows the server refusal for maker-checker inside the confirmation', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({
      '/api/loans/5': { data: loan }, '/api/loan-products': { data: [] },
      'POST /api/loans/5/approve': () => json({ message: 'The person who entered a loan application cannot also decide it.' }, 422),
    })));
    renderWithSession(<LoanView />, { permissions: ['loan.view', 'loan.decide'] });
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Approve' }));

    expect(await screen.findByText('The person who entered a loan application cannot also decide it.')).toBeTruthy();
  });

  it('repays with an idempotency key that is reused when the network fails', async () => {
    const disbursed = { ...loan, status: 'disbursed', classification: 'pass', outstanding_principal: '90000.00', disbursed_on: '2026-10-16' };
    let attempts = 0;
    const fetchMock = vi.fn(serveApi({
      '/api/loans/5': { data: disbursed }, '/api/loan-products': { data: [] }, '/api/members/9/savings-accounts': { data: [] },
      '/api/loans/5/repayment-quote': { data: { date: '2026-10-21', penalty: '0.00', interest: '150.00', principal_overdue: '0.00', outstanding_principal: '90000.00', minimum_payment: '150.00', payoff: '90150.00' } },
      'POST /api/loans/5/repayments': () => {
        attempts += 1;
        if (attempts === 1) throw new TypeError('Failed to fetch');
        return json({ data: { transaction_id: 1, amount: '1000.00', principal: '850.00', interest: '150.00', penalty: '0.00', outstanding_after: '89150.00' } }, 201);
      },
    }));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<LoanView />, { permissions: ['loan.view', 'loan.repay'] });

    fireEvent.change(await screen.findByLabelText(/Amount \(Rs\)/), { target: { value: '1,000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review repayment' }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Post repayment' }));
    expect(await within(screen.getByRole('dialog')).findByText(/Could not reach the server/)).toBeTruthy();
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Post repayment' }));
    expect(await screen.findByText(/Outstanding after/)).toBeTruthy();

    const calls = fetchMock.mock.calls.filter(([u]) => u === '/api/loans/5/repayments');
    const keys = calls.map(([, i]) => (i as { headers: Record<string, string> }).headers['Idempotency-Key']);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toMatch(/^repay-/);
    expect(keys[1]).toBe(keys[0]);
    expect(JSON.parse(String(calls[0][1]!.body)).amount).toBe('1000.00');
  });
});
