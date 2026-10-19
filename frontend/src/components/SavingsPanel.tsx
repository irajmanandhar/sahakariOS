'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useCallback, useEffect, useState } from 'react';
import { api, errorMessage } from '@/lib/api';
import { newIdempotencyKey } from '@/lib/idempotency';

type Account = { id: number; account_number: number; product: { id: number; code: string; name: string; type: string }; status: string; balance: string; maturity_date: string | null };
type Product = { id: number; code: string; name: string; type: string; status: string };
type Line = { id: number; date: string; date_bs: string | null; reference: string | null; particulars: string; debit: string | null; credit: string | null; can_withdraw: string; balance: string };
type Passbook = { legacy_account_number: string | null; minimum_balance: string; accrued_interest: string; transactions: Line[] };

/** Member's savings accounts: open, cash deposit / withdrawal, statement. Amounts are strings end to end. */
export default function SavingsPanel({ memberId, permissions, active }: { memberId: number; permissions: string[]; active: boolean }) {
  const can = (p: string) => permissions.includes(p);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [productId, setProductId] = useState('');
  const [selected, setSelected] = useState<number | null>(null);
  const [book, setBook] = useState<Passbook | null>(null);
  const lines = book?.transactions ?? [];
  const [form, setForm] = useState({ amount: '', note: '', key: newIdempotencyKey('sav') });
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const fetchData = useCallback(() => Promise.all([
    api<{ data: Account[] }>(`/members/${memberId}/savings-accounts`),
    api<{ data: Product[] }>('/savings-products'),
  ]), [memberId]);
  const apply = useCallback(([a, p]: [{ data: Account[] }, { data: Product[] }]) => {
    setAccounts(a.data);
    setProducts(p.data.filter((x) => x.status === 'active'));
  }, []);
  const load = useCallback(() => fetchData().then(apply), [fetchData, apply]);

  useEffect(() => {
    fetchData().then(apply).catch((e) => setMessage({ ok: false, text: errorMessage(e) }));
  }, [fetchData, apply]);

  const statement = useCallback(async (id: number) => {
    setSelected(id);
    setBook((await api<{ data: Passbook }>(`/savings-accounts/${id}`)).data);
  }, []);

  async function run(action: () => Promise<unknown>, ok: string) {
    setMessage(null);
    try {
      await action();
      setMessage({ ok: true, text: ok });
      await load();
      if (selected) await statement(selected);
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) });
    }
  }

  const move = (kind: 'deposits' | 'withdrawals') => run(async () => {
    const extra = kind === 'deposits' ? { source_of_funds: form.note || null } : { large_cash_reason: form.note || null };
    await api(`/savings-accounts/${selected}/${kind}`, { method: 'POST', body: { amount: form.amount, ...extra }, idempotencyKey: form.key });
    setForm({ amount: '', note: '', key: newIdempotencyKey('sav') }); // next action gets a new key
  }, kind === 'deposits' ? 'Deposit posted.' : 'Withdrawal posted.');

  return (
    <Paper sx={{ p: 3, mb: 2 }}>
      <Typography variant="h6" component="h2" gutterBottom>Savings</Typography>
      {message && <Alert severity={message.ok ? 'success' : 'error'} sx={{ mb: 2 }}>{message.text}</Alert>}
      <Table size="small">
        <TableHead>
          <TableRow><TableCell>Account</TableCell><TableCell>Product</TableCell><TableCell>Status</TableCell><TableCell align="right">Balance (Rs)</TableCell><TableCell /></TableRow>
        </TableHead>
        <TableBody>
          {accounts.length === 0 && <TableRow><TableCell colSpan={5}>No savings accounts.</TableCell></TableRow>}
          {accounts.map((a) => (
            <TableRow key={a.id} selected={selected === a.id}>
              <TableCell>{a.account_number}</TableCell>
              <TableCell>{a.product.name}{a.maturity_date && ` (matures ${a.maturity_date})`}</TableCell>
              <TableCell>{a.status}</TableCell>
              <TableCell align="right">{a.balance}</TableCell>
              <TableCell><Button size="small" onClick={() => statement(a.id)}>Select</Button></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {active && can('savings.open') && (
        <Box sx={{ display: 'flex', gap: 2, mt: 2 }}>
          <TextField select size="small" label="Product" value={productId} onChange={(e) => setProductId(e.target.value)} sx={{ minWidth: 240 }}>
            {products.map((p) => <MenuItem key={p.id} value={String(p.id)}>{p.code} · {p.name}</MenuItem>)}
          </TextField>
          <Button variant="outlined" disabled={!productId} onClick={() => run(() => api(`/members/${memberId}/savings-accounts`, { method: 'POST', body: { savings_product_id: Number(productId) } }), 'Account opened.')}>
            Open account
          </Button>
        </Box>
      )}

      {selected && (
        <>
          {(can('savings.deposit') || can('savings.withdraw')) && (
            <Box sx={{ display: 'flex', gap: 2, mt: 3, flexWrap: 'wrap' }}>
              <TextField size="small" label="Amount (Rs)" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
              <TextField size="small" label="Source of funds (deposit ≥ Rs 10 lakh) / reason (withdrawal > Rs 5 lakh)" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} sx={{ flexGrow: 1, minWidth: 280 }} />
              {can('savings.deposit') && <Button variant="contained" onClick={() => move('deposits')}>Cash deposit</Button>}
              {can('savings.withdraw') && <Button variant="outlined" onClick={() => move('withdrawals')}>Cash withdrawal</Button>}
            </Box>
          )}
          <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', mt: 3, flexWrap: 'wrap' }}>
            <Typography variant="subtitle1" component="h3">Passbook</Typography>
            {book?.legacy_account_number && <Typography variant="body2">Old A/c No. {book.legacy_account_number}</Typography>}
            {book && <Typography variant="body2">Minimum balance Rs {book.minimum_balance} · Interest accrued, not yet posted Rs {book.accrued_interest}</Typography>}
            <Button size="small" href={`/api/savings-accounts/${selected}/passbook.csv`}>Download CSV</Button>
          </Box>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>मिति (BS)</TableCell><TableCell>Date (AD)</TableCell><TableCell>Voucher No.</TableCell><TableCell>Particulars</TableCell>
                  <TableCell align="right">Debit</TableCell><TableCell align="right">Credit</TableCell><TableCell align="right">Can withdraw</TableCell><TableCell align="right">Balance</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {lines.length === 0 && <TableRow><TableCell colSpan={8}>No transactions yet.</TableCell></TableRow>}
                {lines.map((l) => (
                  <TableRow key={l.id}>
                    <TableCell>{l.date_bs ?? '—'}</TableCell><TableCell>{l.date}</TableCell><TableCell>{l.reference ?? ''}</TableCell><TableCell>{l.particulars}</TableCell>
                    <TableCell align="right">{l.debit ?? ''}</TableCell><TableCell align="right">{l.credit ?? ''}</TableCell>
                    <TableCell align="right">{l.can_withdraw}</TableCell><TableCell align="right">{l.balance}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </>
      )}
    </Paper>
  );
}
