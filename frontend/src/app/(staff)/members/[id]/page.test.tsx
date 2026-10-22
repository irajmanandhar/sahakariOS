import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MemberPage from './MemberDetail';

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock('next/navigation', () => ({ useRouter: () => router, useParams: () => ({ id: '7' }) }));

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const member = {
  id: 7, member_number: null, status: 'applied', full_name: 'Sita Example', full_name_ne: null, gender: 'female',
  date_of_birth: '1990-05-01', date_of_birth_bs: '2047-01-18', phone: null, is_pep: false, risk_level: null,
  next_kyc_review_on: null, applied_on: '2026-10-16', days_to_decide: 35, decided_at: null, decision_reason: null, documents: [],
};

/** Routes fetch calls by URL; each call gets a fresh Response. */
function serve(permissions: string[], extra: Record<string, unknown> = {}) {
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    void init;
    if (url === '/api/members/7') return json({ data: member });
    if (url === '/api/auth/cooperative') return json({ data: { permissions } });
    if (url in extra) return json(extra[url]);
    return json({ message: 'unexpected' }, 500);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('MemberPage', () => {
  it('shows decision and KYM actions only with permission', async () => {
    serve(['member.view']);
    render(<MemberPage />);

    expect(await screen.findByText('Sita Example')).toBeTruthy();
    expect(screen.getByText('due in 35 days')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(screen.queryByText(/Show KYM details/)).toBeNull();
    expect(screen.queryByText('Upload file')).toBeNull();
  });

  it('approves when permitted', async () => {
    const fetchMock = serve(['member.view', 'member.decide'], { '/api/members/7/approve': { data: member } });
    render(<MemberPage />);

    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));

    expect(await screen.findByText('Member approved.')).toBeTruthy();
    expect(fetchMock.mock.calls.some(([u, i]) => u === '/api/members/7/approve' && (i as RequestInit).method === 'POST')).toBe(true);
  });

  it('uploads documents as multipart form data', async () => {
    const fetchMock = serve(['member.view', 'member.apply'], { '/api/members/7/documents': { data: { id: 1 } } });
    render(<MemberPage />);
    await screen.findByText('Upload file');

    const input = document.querySelector('input[type=file]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'face.png', { type: 'image/png' })] } });

    expect(await screen.findByText('Photo uploaded.')).toBeTruthy();
    const call = fetchMock.mock.calls.find(([u]) => u === '/api/members/7/documents')!;
    expect((call[1] as RequestInit).body).toBeInstanceOf(FormData);
    expect(((call[1] as RequestInit).body as FormData).get('type')).toBe('photo');
  });
});
