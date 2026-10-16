import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LoansPanel from './LoansPanel';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const loan = { id: 5, loan_number: 1, status: 'applied', product: { name: 'Savings-secured', collateral_type: 'savings' }, principal: '90000.00', outstanding_principal: '0.00', term_months: 12, classification: 'pass', disbursed_on: null };

function serve(extra: Record<string, unknown> = {}) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    void init;
    if (url === '/api/members/9/loans') return json({ data: [loan] });
    if (url === '/api/loan-products') return json({ data: [] });
    if (url === '/api/members/9/savings-accounts') return json({ data: [] });
    if (url === '/api/loans/5') return json({ data: { ...loan, schedule: [] } });
    if (url in extra) return json(extra[url]);
    return json({ message: 'The person who entered a loan application cannot also decide it.' }, 422);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('LoansPanel', () => {
  it('hides decisions without permission', async () => {
    serve();
    render(<LoansPanel memberId={9} permissions={['loan.view']} active />);
    fireEvent.click(await screen.findByRole('button', { name: 'Open' }));

    expect(await screen.findByText('Loan 1 · applied')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(screen.queryByText('New loan application')).toBeNull();
  });

  it('shows the server refusal for maker-checker', async () => {
    serve();
    render(<LoansPanel memberId={9} permissions={['loan.view', 'loan.decide']} active />);
    fireEvent.click(await screen.findByRole('button', { name: 'Open' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));

    expect(await screen.findByText('The person who entered a loan application cannot also decide it.')).toBeTruthy();
  });
});
