import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { setPreferences } from '@/lib/preferences';
import DataTable, { downloadCsv } from './DataTable';
import { DateField, MoneyField } from './fields';
import MoneyText from './MoneyText';
import StatusChip from './StatusChip';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }));

afterEach(() => {
  cleanup();
  setPreferences({ locale: 'en', nepaliDigits: false });
});

describe('MoneyText', () => {
  it('groups the API string and switches to Nepali numerals and रु', () => {
    render(<MoneyText value="123456.5" prefix />);
    expect(screen.getByText('Rs 1,23,456.50')).toBeTruthy();
    cleanup();
    setPreferences({ locale: 'ne', nepaliDigits: true });
    render(<MoneyText value="123456.5" prefix />);
    expect(screen.getByText('रु १,२३,४५६.५०')).toBeTruthy();
  });
});

describe('StatusChip', () => {
  it('always shows words, not colour alone', () => {
    render(<StatusChip kind="classification" value="substandard" />);
    expect(screen.getByText('Substandard')).toBeTruthy();
  });
});

describe('DataTable', () => {
  type Row = { id: number; name: string; amount: string };
  const rows: Row[] = [{ id: 1, name: 'B', amount: '100.00' }, { id: 2, name: 'A', amount: '99.99' }, { id: 3, name: 'C', amount: '1000.00' }];
  const columns = [{ key: 'name', header: 'Name' }, { key: 'amount', header: 'Amount', money: true }];

  it('sorts money exactly, filters loaded rows and shows the empty state', () => {
    render(<DataTable label="Test" columns={columns} rows={rows} rowKey={(r) => r.id} quickFilter />);
    const table = screen.getByRole('table', { name: 'Test' });
    fireEvent.click(within(table).getByText('Amount'));
    expect(within(table).getAllByRole('row').slice(1).map((r) => r.textContent)).toEqual(['A99.99', 'B100.00', 'C1,000.00']);
    fireEvent.change(screen.getByLabelText('Search: Test'), { target: { value: 'zzz' } });
    expect(screen.getByText('Nothing to show yet.')).toBeTruthy();
  });

  it('exports CSV with a BOM and neutralises spreadsheet formulas', async () => {
    let blob: Blob | undefined;
    URL.createObjectURL = vi.fn((b: Blob) => { blob = b; return 'blob:x'; });
    URL.revokeObjectURL = vi.fn();
    downloadCsv('x', ['Name', 'Amount'], [['=HYPERLINK("x")', '-25.50'], ['a,b', '"q"']]);
    const bytes = new Uint8Array(await blob!.arrayBuffer());
    expect([...bytes.slice(0, 3)]).toEqual([0xef, 0xbb, 0xbf]); // UTF-8 BOM so Excel reads Nepali text
    expect(new TextDecoder().decode(bytes.slice(3))).toBe('Name,Amount\r\n"\'=HYPERLINK(""x"")",-25.50\r\n"a,b","""q"""');
  });
});

describe('fields', () => {
  function Harness() {
    const [date, setDate] = useState('');
    const [money, setMoney] = useState('');
    return (
      <>
        <DateField label="When" value={date} onChange={setDate} />
        <MoneyField label="Amount" value={money} onChange={setMoney} />
        <output>{date}</output>
      </>
    );
  }

  it('enters BS, sends AD, and refuses dates outside the verified calendar', () => {
    render(<Harness />);
    fireEvent.change(screen.getByLabelText('When (BS)'), { target: { value: '2083-06-30' } });
    expect(screen.getByRole('status').textContent).toBe('2026-10-16');
    fireEvent.change(screen.getByLabelText('When (BS)'), { target: { value: '2090-01-01' } });
    expect(screen.getByText(/Not a BS date/)).toBeTruthy();
  });

  it('confirms the grouped amount and flags invalid money', () => {
    render(<Harness />);
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '100000' } });
    expect(screen.getByText('Rs 1,00,000.00')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '1.005' } });
    expect(screen.getByText(/at most 2 decimals/)).toBeTruthy();
  });
});
