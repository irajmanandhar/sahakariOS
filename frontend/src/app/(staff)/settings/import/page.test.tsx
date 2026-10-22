import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ImportPage from './page';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }));

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('ImportPage', () => {
  it('imports only after a passing check, and shows what to fix', async () => {
    const sent: string[] = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      const dry = (init.body as FormData).get('dry_run') === '1';
      sent.push(`${url} ${dry ? 'check' : 'import'}`);
      return json({ data: { ok: true, dry_run: dry, lines: [dry ? '[dry run] 1 member(s) would be imported' : 'Imported 1 member(s)'] } });
    }));
    const { container } = render(<ImportPage />);
    const importButtons = screen.getAllByRole('button', { name: 'Import' });
    expect((importButtons[0] as HTMLButtonElement).disabled).toBe(true);

    const input = container.querySelectorAll('input[type=file]')[0] as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['member_number\n'], 'members.csv', { type: 'text/csv' })] } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Check (nothing is saved)' })[0]);
    expect(await screen.findByText('Check passed. You can import now.')).toBeTruthy();

    fireEvent.click(screen.getAllByRole('button', { name: 'Import' })[0]);
    expect(await screen.findByText('Imported 1 member(s)')).toBeTruthy();
    expect(sent).toEqual(['/api/imports/members check', '/api/imports/members import']);
  });

  it('lists the problems of a failed check', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json({ data: { ok: false, dry_run: true, lines: ['Row 3: member 8 does not exist (import members first).'] } })));
    const { container } = render(<ImportPage />);
    fireEvent.change(container.querySelectorAll('input[type=file]')[1], { target: { files: [new File(['x'], 'history.csv')] } });
    fireEvent.click(screen.getAllByRole('button', { name: 'Check (nothing is saved)' })[1]);
    expect(await screen.findByText('Row 3: member 8 does not exist (import members first).')).toBeTruthy();
    expect((screen.getAllByRole('button', { name: 'Import' })[1] as HTMLButtonElement).disabled).toBe(true);
  });
});
