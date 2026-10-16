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

type Loan = { id: number; loan_number: number; status: string; product: { name: string; collateral_type: string }; principal: string; outstanding_principal: string; term_months: number; classification: string; disbursed_on: string | null };
type Product = { id: number; code: string; name: string; collateral_type: string; status: string };
type Detail = Loan & { schedule: { installment_number: number; due_date: string; principal_due: string; interest_projected: string; principal_paid: string }[] };
type Savings = { id: number; account_number: number; product: { name: string } };

const empty = { loan_product_id: '', principal: '', term_months: '12', grace_months: '0', purpose: '', pledged_savings_account_id: '', collateral_value: '', collateral_description: '', guarantors: '' };

/** Member's loans: apply, decide (a different person), disburse into own savings, schedule. */
export default function LoansPanel({ memberId, permissions, active }: { memberId: number; permissions: string[]; active: boolean }) {
  const can = (p: string) => permissions.includes(p);
  const [loans, setLoans] = useState<Loan[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [savings, setSavings] = useState<Savings[]>([]);
  const [form, setForm] = useState(empty);
  const [detail, setDetail] = useState<Detail | null>(null);
  const [into, setInto] = useState('');
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const fetchAll = useCallback(() => Promise.all([
    api<{ data: Loan[] }>(`/members/${memberId}/loans`),
    api<{ data: Product[] }>('/loan-products'),
    api<{ data: Savings[] }>(`/members/${memberId}/savings-accounts`).catch(() => ({ data: [] as Savings[] })),
  ]), [memberId]);
  const apply = useCallback(([l, p, s]: [{ data: Loan[] }, { data: Product[] }, { data: Savings[] }]) => {
    setLoans(l.data);
    setProducts(p.data.filter((x) => x.status === 'active'));
    setSavings(s.data);
  }, []);
  useEffect(() => {
    fetchAll().then(apply).catch((e) => setMessage({ ok: false, text: errorMessage(e) }));
  }, [fetchAll, apply]);

  const open = async (id: number) => setDetail((await api<{ data: Detail }>(`/loans/${id}`)).data);

  async function run(action: () => Promise<unknown>, ok: string) {
    setMessage(null);
    try {
      await action();
      setMessage({ ok: true, text: ok });
      apply(await fetchAll());
      if (detail) await open(detail.id);
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) });
    }
  }

  const product = products.find((p) => String(p.id) === form.loan_product_id);
  const submit = () => run(async () => {
    await api(`/members/${memberId}/loans`, { method: 'POST', body: {
      loan_product_id: Number(form.loan_product_id), principal: form.principal, term_months: Number(form.term_months),
      grace_months: Number(form.grace_months || 0), purpose: form.purpose,
      pledged_savings_account_id: form.pledged_savings_account_id ? Number(form.pledged_savings_account_id) : null,
      collateral_value: form.collateral_value || null, collateral_description: form.collateral_description || null,
      guarantor_member_ids: form.guarantors.split(',').map((x) => Number(x.trim())).filter(Boolean),
    } });
    setForm(empty);
  }, 'Loan application recorded.');

  const field = (key: keyof typeof empty, label: string, children?: React.ReactNode) => (
    <TextField size="small" label={label} select={!!children} value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })} sx={{ minWidth: 180 }}>
      {children}
    </TextField>
  );

  return (
    <Paper sx={{ p: 3, mb: 2 }}>
      <Typography variant="h6" component="h2" gutterBottom>Loans</Typography>
      {message && <Alert severity={message.ok ? 'success' : 'error'} sx={{ mb: 2 }}>{message.text}</Alert>}
      <Table size="small">
        <TableHead>
          <TableRow><TableCell>No.</TableCell><TableCell>Product</TableCell><TableCell>Status</TableCell><TableCell align="right">Principal</TableCell><TableCell align="right">Outstanding</TableCell><TableCell>Class</TableCell><TableCell /></TableRow>
        </TableHead>
        <TableBody>
          {loans.length === 0 && <TableRow><TableCell colSpan={7}>No loans.</TableCell></TableRow>}
          {loans.map((l) => (
            <TableRow key={l.id} selected={detail?.id === l.id}>
              <TableCell>{l.loan_number}</TableCell><TableCell>{l.product.name}</TableCell><TableCell>{l.status}</TableCell>
              <TableCell align="right">{l.principal}</TableCell><TableCell align="right">{l.outstanding_principal}</TableCell><TableCell>{l.classification}</TableCell>
              <TableCell><Button size="small" onClick={() => open(l.id)}>Open</Button></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {detail && (
        <Box sx={{ mt: 2 }}>
          <Typography variant="subtitle1">Loan {detail.loan_number} · {detail.status}</Typography>
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', my: 1 }}>
            {detail.status === 'applied' && can('loan.decide') && <>
              <Button variant="contained" color="success" onClick={() => run(() => api(`/loans/${detail.id}/approve`, { method: 'POST' }), 'Loan approved.')}>Approve</Button>
              <TextField size="small" label="Rejection reason" value={reason} onChange={(e) => setReason(e.target.value)} />
              <Button color="error" onClick={() => run(() => api(`/loans/${detail.id}/reject`, { method: 'POST', body: { reason } }), 'Loan rejected.')}>Reject</Button>
            </>}
            {detail.status === 'approved' && can('loan.disburse') && <>
              <TextField select size="small" label="Disburse into savings account" value={into} onChange={(e) => setInto(e.target.value)} sx={{ minWidth: 260 }}>
                {savings.map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.account_number} · {s.product.name}</MenuItem>)}
              </TextField>
              <Button variant="contained" disabled={!into} onClick={() => run(() => api(`/loans/${detail.id}/disburse`, { method: 'POST', body: { savings_account_id: Number(into) }, idempotencyKey: newIdempotencyKey('disburse') }), 'Loan disbursed.')}>Disburse</Button>
            </>}
          </Box>
          {detail.schedule.length > 0 && (
            <Table size="small">
              <TableHead><TableRow><TableCell>#</TableCell><TableCell>Due</TableCell><TableCell align="right">Principal</TableCell><TableCell align="right">Interest (est.)</TableCell><TableCell align="right">Paid</TableCell></TableRow></TableHead>
              <TableBody>
                {detail.schedule.map((s) => (
                  <TableRow key={s.installment_number}><TableCell>{s.installment_number}</TableCell><TableCell>{s.due_date}</TableCell><TableCell align="right">{s.principal_due}</TableCell><TableCell align="right">{s.interest_projected}</TableCell><TableCell align="right">{s.principal_paid}</TableCell></TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Box>
      )}

      {active && can('loan.apply') && (
        <Box sx={{ mt: 3 }}>
          <Typography variant="subtitle1" gutterBottom>New loan application</Typography>
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
            {field('loan_product_id', 'Product', products.map((p) => <MenuItem key={p.id} value={String(p.id)}>{p.code} · {p.name} ({p.collateral_type})</MenuItem>))}
            {field('principal', 'Amount (Rs)')}
            {field('term_months', 'Term (months)')}
            {field('grace_months', 'Grace (months)')}
            {field('purpose', 'Purpose')}
            {product?.collateral_type === 'savings' && field('pledged_savings_account_id', 'Pledged savings account', savings.map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.account_number} · {s.product.name}</MenuItem>))}
            {product?.collateral_type === 'real_estate' && <>{field('collateral_value', 'Collateral valuation (Rs)')}{field('collateral_description', 'Collateral description')}</>}
            {product?.collateral_type === 'unsecured' && field('guarantors', 'Guarantor member IDs (comma-separated)')}
            <Button variant="contained" onClick={submit}>Apply</Button>
          </Box>
        </Box>
      )}
    </Paper>
  );
}
