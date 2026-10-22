import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithSession, serveApi } from '@/test/render';
import MemberDetail from './MemberDetail';

let tab: string | null = null;
const router = { replace: vi.fn((url: string) => { tab = new URL(url, 'http://x').searchParams.get('tab'); }), push: vi.fn() };
vi.mock('next/navigation', () => ({
  useRouter: () => router,
  useParams: () => ({ id: '7' }),
  useSearchParams: () => new URLSearchParams(tab ? `tab=${tab}` : ''),
}));

const member = {
  id: 7, member_number: null, status: 'applied', full_name: 'Sita Example', full_name_ne: null, gender: 'female',
  date_of_birth: '1990-05-01', date_of_birth_bs: '2047-01-18', phone: null, is_pep: false, risk_level: null,
  next_kyc_review_on: null, applied_on: '2026-10-16', days_to_decide: 35, decided_at: null, decision_reason: null, documents: [],
};

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  tab = null;
});

describe('MemberDetail', () => {
  it('shows tabs and actions only with permission', async () => {
    vi.stubGlobal('fetch', vi.fn(serveApi({ '/api/members/7': { data: member } })));
    renderWithSession(<MemberDetail />, { permissions: ['member.view'] });

    expect(await screen.findByText('Sita Example')).toBeTruthy();
    expect(screen.getAllByText('Decide within 35 days').length).toBeGreaterThan(0);
    expect(screen.queryByRole('tab', { name: 'KYM' })).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Risk and decision' })).toBeNull();
  });

  it('approves after confirmation when permitted', async () => {
    tab = 'compliance';
    const fetchMock = vi.fn(serveApi({
      '/api/members/7': { data: member },
      'POST /api/members/7/approve': { data: { ...member, status: 'active', member_number: 12 } },
    }));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<MemberDetail />, { permissions: ['member.view', 'member.decide'] });

    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Approve' }));

    expect(await screen.findByText('Approved as member no. 12.')).toBeTruthy();
    expect(fetchMock.mock.calls.some(([u, i]) => u === '/api/members/7/approve' && i?.method === 'POST')).toBe(true);
  });

  it('requires a reason to reject', async () => {
    tab = 'compliance';
    vi.stubGlobal('fetch', vi.fn(serveApi({ '/api/members/7': { data: member } })));
    renderWithSession(<MemberDetail />, { permissions: ['member.view', 'member.decide'] });

    fireEvent.click(await screen.findByRole('button', { name: 'Reject' }));
    const dialog = await screen.findByRole('dialog');
    expect((within(dialog).getByRole('button', { name: 'Reject' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText(/Reason for rejection/), { target: { value: 'Lives outside the working area' } });
    expect((within(dialog).getByRole('button', { name: 'Reject' }) as HTMLButtonElement).disabled).toBe(false);
  });

  it('uploads documents as multipart form data and refuses oversized files', async () => {
    tab = 'documents';
    const fetchMock = vi.fn(serveApi({ '/api/members/7': { data: member }, 'POST /api/members/7/documents': { data: { id: 1 } } }));
    vi.stubGlobal('fetch', fetchMock);
    renderWithSession(<MemberDetail />, { permissions: ['member.view', 'member.apply'] });
    await screen.findByText('Choose file');
    const input = document.querySelector('input[type=file]') as HTMLInputElement;

    const big = new File(['x'], 'big.png', { type: 'image/png' });
    Object.defineProperty(big, 'size', { value: 6 * 1024 * 1024 });
    fireEvent.change(input, { target: { files: [big] } });
    expect(screen.getByText('The file is larger than 5 MB.')).toBeTruthy();

    globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview');
    globalThis.URL.revokeObjectURL = vi.fn();
    fireEvent.change(input, { target: { files: [new File(['x'], 'face.png', { type: 'image/png' })] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));

    expect(await screen.findByText('Photo uploaded.')).toBeTruthy();
    const call = fetchMock.mock.calls.find(([u]) => u === '/api/members/7/documents')!;
    expect(call[1]!.body).toBeInstanceOf(FormData);
    expect((call[1]!.body as FormData).get('type')).toBe('photo');
  });
});
