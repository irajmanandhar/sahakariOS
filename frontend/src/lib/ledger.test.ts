import { describe, expect, it } from 'vitest';
import { journalTotals, treeOrder } from './ledger';

const line = (account: string, debit = '', credit = '') => ({ account, debit, credit, description: '' });

describe('journalTotals', () => {
  it('balances exactly, without floating point error', () => {
    const t = journalTotals([line('1.1', '0.10'), line('1.2', '0.20'), line('2.1', '', '0.30')]);
    expect(t).toMatchObject({ debit: '0.30', credit: '0.30', difference: '0.00', balanced: true });
  });

  it('is not balanced when sides differ, a line is incomplete, or totals are zero', () => {
    expect(journalTotals([line('1.1', '100'), line('2.1', '', '99.99')])).toMatchObject({ difference: '0.01', balanced: false });
    expect(journalTotals([line('1.1', '100'), line('', '', '100')]).balanced).toBe(false);
    expect(journalTotals([line('1.1', '0'), line('2.1', '', '0')]).balanced).toBe(false);
    expect(journalTotals([line('1.1', '100')]).balanced).toBe(false);
  });

  it('ignores amounts that are not valid money', () => {
    expect(journalTotals([line('1.1', '1.005'), line('2.1', '', '1')])).toMatchObject({ debit: '0.00', balanced: false });
  });
});

describe('treeOrder', () => {
  it('puts children under their parents, sorted by code', () => {
    const a = (id: number, code: string, parent: number | null) => ({ id, code, name: code, type: 'asset' as const, normal_side: 'debit' as const, is_postable: parent !== null, parent_id: parent, status: 'active' });
    const rows = treeOrder([a(3, '1.10', 1), a(1, '1', null), a(2, '1.2', 1), a(4, '2', null)]);
    expect(rows.map((r) => `${r.depth}:${r.code}`)).toEqual(['0:1', '1:1.2', '1:1.10', '0:2']);
  });
});
