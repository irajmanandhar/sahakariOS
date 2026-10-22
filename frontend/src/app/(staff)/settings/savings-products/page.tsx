'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Container from '@mui/material/Container';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { api, currentPermissions, errorMessage, redirectForAuthError } from '@/lib/api';

type Product = { id: number; code: string; name: string; type: string; interest_rate: string; interest_posting: string; minimum_balance: string; term_months: number | null; status: string };
const empty = { code: '', name: '', type: 'general', interest_rate: '', interest_posting: 'quarterly', minimum_balance: '0.00', term_months: '', ledger_account_code: '' };

/** Savings products. Rates and terms are decisions of the board (NCRA Std 5/2.1); nothing is pre-filled. */
export default function SavingsProductsPage() {
  const router = useRouter();
  const [products, setProducts] = useState<Product[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [form, setForm] = useState(empty);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const fail = useCallback((e: unknown) => redirectForAuthError(e, router.replace) || setMessage({ ok: false, text: errorMessage(e) }), [router]);
  const fetchAll = useCallback(() => Promise.all([api<{ data: Product[] }>('/savings-products'), currentPermissions()]), []);
  useEffect(() => {
    fetchAll().then(([p, perms]) => {
      setProducts(p.data);
      setCanManage(perms.includes('savings.product_manage'));
    }).catch(fail);
  }, [fetchAll, fail]);

  async function create() {
    setMessage(null);
    try {
      await api('/savings-products', { method: 'POST', body: { ...form, term_months: form.type === 'term' ? Number(form.term_months) : null } });
      setForm(empty);
      setMessage({ ok: true, text: 'Product created.' });
      const [p] = await fetchAll();
      setProducts(p.data);
    } catch (e) {
      fail(e);
    }
  }

  const field = (key: keyof typeof empty, label: string, select?: [string, string][]) => (
    <TextField size="small" label={label} select={!!select} value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })} sx={{ minWidth: 160 }}>
      {select?.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
    </TextField>
  );

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>Savings products</Typography>
      {message && <Alert severity={message.ok ? 'success' : 'error'} sx={{ mb: 2 }}>{message.text}</Alert>}
      <Paper sx={{ overflowX: 'auto', mb: 3 }}>
        <Table size="small">
          <TableHead>
            <TableRow><TableCell>Code</TableCell><TableCell>Name</TableCell><TableCell>Type</TableCell><TableCell>Rate %</TableCell><TableCell>Interest posting</TableCell><TableCell>Min. balance</TableCell><TableCell>Term</TableCell></TableRow>
          </TableHead>
          <TableBody>
            {products.length === 0 && <TableRow><TableCell colSpan={7}>No products yet.</TableCell></TableRow>}
            {products.map((p) => (
              <TableRow key={p.id}><TableCell>{p.code}</TableCell><TableCell>{p.name}</TableCell><TableCell>{p.type}</TableCell><TableCell>{p.interest_rate}</TableCell><TableCell>{p.interest_posting}</TableCell><TableCell>{p.minimum_balance}</TableCell><TableCell>{p.term_months ? `${p.term_months} months` : '—'}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>
      {canManage && (
        <Paper sx={{ p: 3 }}>
          <Typography variant="h6" component="h2" gutterBottom>New product</Typography>
          <Alert severity="info" sx={{ mb: 2 }}>Enter the rate and terms approved by the board. Savings and loan rates must keep a spread of at most 6% (Act s.50(4)).</Alert>
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
            {field('code', 'Code')}
            {field('name', 'Name')}
            {field('type', 'Type', [['general', 'General'], ['regular', 'Regular'], ['term', 'Term (≤ 36 months)']])}
            {field('interest_rate', 'Annual rate %')}
            {field('interest_posting', 'Interest posting', [['monthly', 'Monthly'], ['quarterly', 'Quarterly'], ['half_yearly', 'Half-yearly'], ['yearly', 'Yearly'], ['at_maturity', 'At maturity']])}
            {field('minimum_balance', 'Minimum balance (Rs)')}
            {form.type === 'term' && field('term_months', 'Term (months)')}
            {field('ledger_account_code', 'Liability account code')}
            <Button variant="contained" onClick={create}>Create</Button>
          </Box>
        </Paper>
      )}
    </Container>
  );
}
