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

type Product = { id: number; code: string; name: string; collateral_type: string; interest_rate: string; penalty_percent: string; service_fee_percent: string; repayment_frequency: string; schedule_method: string; max_term_months: number; max_amount: string; max_ltv_percent: string | null };
const empty = { code: '', name: '', collateral_type: 'savings', interest_rate: '', penalty_percent: '0', service_fee_percent: '0', repayment_frequency: 'monthly', schedule_method: 'equal_principal', max_term_months: '12', max_grace_months: '0', max_amount: '', max_ltv_percent: '', ledger_account_code: '' };

/** Loan products. Rates, fees and limits are the cooperative's decisions within the Act and NCRA standards. */
export default function LoanProductsPage() {
  const router = useRouter();
  const [products, setProducts] = useState<Product[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [form, setForm] = useState(empty);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const fail = useCallback((e: unknown) => redirectForAuthError(e, router.replace) || setMessage({ ok: false, text: errorMessage(e) }), [router]);
  const fetchAll = useCallback(() => Promise.all([api<{ data: Product[] }>('/loan-products'), currentPermissions()]), []);
  useEffect(() => {
    fetchAll().then(([p, perms]) => {
      setProducts(p.data);
      setCanManage(perms.includes('loan.product_manage'));
    }).catch(fail);
  }, [fetchAll, fail]);

  async function create() {
    setMessage(null);
    try {
      await api('/loan-products', { method: 'POST', body: { ...form, max_term_months: Number(form.max_term_months), max_grace_months: Number(form.max_grace_months), max_ltv_percent: form.collateral_type === 'real_estate' ? form.max_ltv_percent : null } });
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
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>Loan products</Typography>
      {message && <Alert severity={message.ok ? 'success' : 'error'} sx={{ mb: 2 }}>{message.text}</Alert>}
      <Paper sx={{ overflowX: 'auto', mb: 3 }}>
        <Table size="small">
          <TableHead><TableRow><TableCell>Code</TableCell><TableCell>Name</TableCell><TableCell>Collateral</TableCell><TableCell>Rate %</TableCell><TableCell>Penalty % of overdue interest</TableCell><TableCell>Fee %</TableCell><TableCell>Repayment</TableCell><TableCell>Max</TableCell></TableRow></TableHead>
          <TableBody>
            {products.length === 0 && <TableRow><TableCell colSpan={8}>No products yet.</TableCell></TableRow>}
            {products.map((p) => (
              <TableRow key={p.id}><TableCell>{p.code}</TableCell><TableCell>{p.name}</TableCell><TableCell>{p.collateral_type}{p.max_ltv_percent && ` (LTV ${p.max_ltv_percent}%)`}</TableCell><TableCell>{p.interest_rate}</TableCell><TableCell>{p.penalty_percent}</TableCell><TableCell>{p.service_fee_percent}</TableCell><TableCell>{p.repayment_frequency}, {p.schedule_method.replace('_', ' ')}</TableCell><TableCell>Rs {p.max_amount} / {p.max_term_months} m</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>
      {canManage && (
        <Paper sx={{ p: 3 }}>
          <Typography variant="h6" component="h2" gutterBottom>New product</Typography>
          <Alert severity="info" sx={{ mb: 2 }}>Penalty interest may not exceed 5% of overdue interest (Act s.50(5क)); the savings–loan spread may not exceed 6% (s.50(4)). Gold-collateral and overdraft loans are prohibited (NCRA Std 9/8).</Alert>
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
            {field('code', 'Code')}{field('name', 'Name')}
            {field('collateral_type', 'Collateral', [['savings', 'Own savings'], ['real_estate', 'Real estate'], ['unsecured', 'Unsecured (guarantors)']])}
            {field('interest_rate', 'Annual rate %')}{field('penalty_percent', 'Penalty % (≤ 5)')}{field('service_fee_percent', 'Service fee %')}
            {field('repayment_frequency', 'Repayment', [['monthly', 'Monthly'], ['quarterly', 'Quarterly']])}
            {field('schedule_method', 'Schedule', [['equal_principal', 'Equal principal'], ['equal_installment', 'Equal instalment (EMI)']])}
            {field('max_term_months', 'Max term (months)')}{field('max_grace_months', 'Max grace (months)')}{field('max_amount', 'Max amount (Rs)')}
            {form.collateral_type === 'real_estate' && field('max_ltv_percent', 'Max loan-to-value %')}
            {field('ledger_account_code', 'Loan asset account code')}
            <Button variant="contained" onClick={create}>Create</Button>
          </Box>
        </Paper>
      )}
    </Container>
  );
}
