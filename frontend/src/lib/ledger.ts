import { fromPaisa, parseMoneyInput, toPaisa } from './money';
import type { LedgerAccount } from './types';

export type JournalLineInput = { account: string; debit: string; credit: string; description: string };

export const blankLine = (): JournalLineInput => ({ account: '', debit: '', credit: '', description: '' });

/**
 * Exact totals of typed journal lines (BigInt paisa). Balanced means: at least two complete lines,
 * each one-sided with a valid amount, and debits = credits > 0 — the same rules the API enforces.
 */
export function journalTotals(lines: JournalLineInput[]) {
  const sum = (side: 'debit' | 'credit') => lines.reduce((total, l) => total + toPaisa(parseMoneyInput(l[side]) ?? '0'), 0n);
  const debit = sum('debit');
  const credit = sum('credit');
  const oneSided = lines.every((l) => !(l.debit && l.credit));
  const complete = lines.length >= 2 && lines.every((l) => l.account && (parseMoneyInput(l.debit) ?? parseMoneyInput(l.credit)));
  return { debit: fromPaisa(debit), credit: fromPaisa(credit), difference: fromPaisa(debit - credit), balanced: debit === credit && debit > 0n && oneSided && !!complete };
}

export type AccountRow = LedgerAccount & { depth: number };

/** Accounts in tree order (parents before children), with their depth. */
export function treeOrder(accounts: LedgerAccount[]): AccountRow[] {
  const children = new Map<number | null, LedgerAccount[]>();
  accounts.forEach((a) => children.set(a.parent_id, [...(children.get(a.parent_id) ?? []), a]));
  const known = new Set(accounts.map((a) => a.id));
  const out: AccountRow[] = [];
  const walk = (parent: number | null, depth: number) => (children.get(parent) ?? []).sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true })).forEach((a) => {
    out.push({ ...a, depth });
    walk(a.id, depth + 1);
  });
  walk(null, 0);
  accounts.filter((a) => a.parent_id !== null && !known.has(a.parent_id)).forEach((a) => { out.push({ ...a, depth: 0 }); walk(a.id, 1); });
  return out;
}

