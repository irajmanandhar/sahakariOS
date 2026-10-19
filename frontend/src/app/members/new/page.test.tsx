import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import NewMemberPage from './page';

const push = vi.fn();
const router = { replace: vi.fn(), push };
vi.mock('next/navigation', () => ({ useRouter: () => router }));

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

beforeEach(() => push.mockReset());
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('NewMemberPage', () => {
  it('sends nested KYM fields and shows field errors from the server', async () => {
    const fetchMock = vi.fn().mockResolvedValue(json({
      message: 'The kym.mother name field is required.',
      errors: { 'kym.mother_name': ['The kym.mother name field is required.'] },
    }, 422));
    vi.stubGlobal('fetch', fetchMock);
    window.scrollTo = vi.fn();
    render(<NewMemberPage />);

    fireEvent.change(screen.getByLabelText(/Full name \(as on citizenship\)/), { target: { value: 'Sita Example' } });
    fireEvent.change(screen.getByLabelText(/Permanent: ward/), { target: { value: '5' } });
    fireEvent.click(screen.getByLabelText(/I am a Nepali citizen/));
    fireEvent.click(screen.getByRole('button', { name: /submit application/i }));

    expect((await screen.findAllByText('The kym.mother name field is required.')).length).toBeGreaterThan(0);
    const body = JSON.parse(fetchMock.mock.calls[0][1].body);
    expect(body.full_name).toBe('Sita Example');
    expect(body.kym.permanent_address.ward).toBe(5);
    expect(body.declares_nepali_citizen).toBe(true);
    expect(body.kym.pep.is_pep).toBe(false);
    expect(push).not.toHaveBeenCalled();
  }, 15_000); // long form: about 2 s alone, slower when the machine is busy

  it('opens the new member after a successful application', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ data: { id: 42 } }, 201)));
    render(<NewMemberPage />);

    fireEvent.click(screen.getByRole('button', { name: /submit application/i }));

    await vi.waitFor(() => expect(push).toHaveBeenCalledWith('/members/42'));
  });
});
