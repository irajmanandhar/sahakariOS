import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { json, renderWithSession } from '@/test/render';
import NewMemberPage from './page';

const push = vi.fn();
const router = { replace: vi.fn(), push };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

beforeEach(() => push.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const next = () => fireEvent.click(screen.getByRole('button', { name: 'Next' }));

describe('NewMemberPage', () => {
  it('collects every step, sends nested KYM fields and returns to the step the server rejected', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({
      message: 'The kym.mother name field is required.',
      errors: { 'kym.mother_name': ['The kym.mother name field is required.'] },
    }, 422));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<NewMemberPage />, { permissions: ['member.apply'] });

    fireEvent.change(screen.getByLabelText(/Full name \(as on citizenship\)/), { target: { value: 'Sita Example' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enter in AD' })); // date of birth in AD
    fireEvent.change(screen.getByLabelText(/Date of birth \(AD\)/), { target: { value: '1990-05-01' } });
    next(); // → identity documents
    next(); // → family
    next(); // → occupation
    next(); // → residence
    fireEvent.change(screen.getByLabelText(/Permanent: ward/), { target: { value: '5' } });
    next(); // → membership
    next(); // → income
    fireEvent.change(screen.getByLabelText(/Expected annual deposit/), { target: { value: '1,20,000' } });
    next(); // → declarations
    fireEvent.click(screen.getByLabelText(/I am a Nepali citizen/));
    next(); // → review
    expect(screen.getByText('Sita Example')).toBeTruthy();
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /submit application/i })); });

    expect((await screen.findAllByText('The kym.mother name field is required.')).length).toBeGreaterThan(0);
    expect(screen.getByRole('heading', { name: 'ख. Family' })).toBeTruthy();
    const body = JSON.parse(fetchMock.mock.calls[0][1].body);
    expect(body.full_name).toBe('Sita Example');
    expect(body.date_of_birth).toBe('1990-05-01');
    expect(body.kym.permanent_address.ward).toBe(5);
    expect(body.kym.expected_annual_deposit).toBe('120000.00');
    expect(body.declares_nepali_citizen).toBe(true);
    expect(body.kym.pep.is_pep).toBe(false);
    expect(body.__apply).toBeUndefined();
    expect(push).not.toHaveBeenCalled();
  }, 20_000);

  it('converts a BS date of birth to AD for the API', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({ data: { id: 42 } }, 201));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<NewMemberPage />, { permissions: ['member.apply'] });

    fireEvent.change(screen.getByLabelText(/Date of birth \(BS\)/), { target: { value: '2047-01-18' } });
    expect(screen.getByText('1990-05-01 AD')).toBeTruthy();
    for (let i = 0; i < 8; i++) next();
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /submit application/i })); });

    await vi.waitFor(() => expect(push).toHaveBeenCalledWith('/members/42'));
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).date_of_birth).toBe('1990-05-01');
  });
});
