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
type Line = { id: number; date: string; type: string; channel: string; amount: string; balance_after: string };

/** Member's savings accounts: open, cash deposit / withdrawal, statement. Amounts are strings end to end. */
export default function SavingsPanel({ memberId, permissions, active }: { memberId: number; permissions: string[]; active: boolean }) {
  const can = (p: string) => permissions.includes(p);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [productId, setProductId] = useState('');
  const [selected, setSelected] = useState<number | null>(null);
  const [lines, setLines] = useState<Line[]>([]);
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
    const { data } = await api<{ data: { transactions: Line[] } }>(`/savings-accounts/${id}`);
    setLines(data.transactions);
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
          <Table size="small" sx={{ mt: 2 }}>
            <TableHead>
              <TableRow><TableCell>Date</TableCell><TableCell>Type</TableCell><TableCell align="right">Amount</TableCell><TableCell align="right">Balance</TableCell></TableRow>
            </TableHead>
            <TableBody>
              {lines.length === 0 && <TableRow><TableCell colSpan={4}>No transactions yet.</TableCell></TableRow>}
              {lines.map((l) => (
                <TableRow key={l.id}><TableCell>{l.date}</TableCell><TableCell>{l.type} ({l.channel})</TableCell><TableCell align="right">{l.amount}</TableCell><TableCell align="right">{l.balance_after}</TableCell></TableRow>
              ))}
            </TableBody>
          </Table>
        </>
      )}
    </Paper>
  );
}
