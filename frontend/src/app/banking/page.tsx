'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Container from '@mui/material/Container';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { api, currentPermissions, errorMessage, redirectForAuthError } from '@/lib/api';

type Item = { date: string; description: string; amount: string };
type Recon = { ledger_balance: string; statement_balance: string; adjusted_ledger_balance: string; reconciled: boolean; unmatched_ledger: Item[]; unmatched_statement: Item[] };
type Sms = { id: number; body: string; status: string; attempts: number; created_at: string };

/** Bank statement import + reconciliation, and the member SMS outbox. */
export default function BankingPage() {
  const router = useRouter();
  const [banks, setBanks] = useState<string[]>([]);
  const [code, setCode] = useState('');
  const [statementBalance, setStatementBalance] = useState('');
  const [recon, setRecon] = useState<Recon | null>(null);
  const [sms, setSms] = useState<Sms[] | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const fail = useCallback((e: unknown) => redirectForAuthError(e, router.replace) || setMessage({ ok: false, text: errorMessage(e) }), [router]);
  useEffect(() => {
    Promise.all([api<{ data: { assigned: { code: string; tag: string }[] } }>('/ledger-account-tags'), currentPermissions()]).then(async ([t, p]) => {
      setBanks([...new Set(t.data.assigned.filter((a) => a.tag.endsWith('bank_deposit')).map((a) => a.code))]);
      if (p.includes('notification.view')) setSms((await api<{ data: Sms[] }>('/sms-messages')).data);
    }).catch(fail);
  }, [fail]);

  async function upload(file: File) {
    const form = new FormData();
    form.append('file', file);
    try {
      const { data } = await api<{ data: { imported: number; matched: number } }>(`/bank-accounts/${code}/statements`, { form });
      setMessage({ ok: true, text: `Imported ${data.imported} line(s), matched ${data.matched}.` });
    } catch (e) {
      fail(e);
    }
  }

  async function reconcile() {
    try {
      setRecon((await api<{ data: Recon }>(`/bank-accounts/${code}/reconciliation?statement_balance=${encodeURIComponent(statementBalance)}`)).data);
    } catch (e) {
      fail(e);
    }
  }

  const list = (title: string, items: Item[]) => (
    <Paper sx={{ mb: 2 }}>
      <Typography variant="subtitle1" sx={{ p: 2, pb: 0 }}>{title}</Typography>
      <Table size="small"><TableBody>
        {items.length === 0 && <TableRow><TableCell>None.</TableCell></TableRow>}
        {items.map((i, n) => <TableRow key={n}><TableCell>{i.date}</TableCell><TableCell>{i.description}</TableCell><TableCell align="right">{i.amount}</TableCell></TableRow>)}
      </TableBody></Table>
    </Paper>
  );

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>Bank reconciliation</Typography>
      {message && <Alert severity={message.ok ? 'success' : 'error'} sx={{ mb: 2 }}>{message.text}</Alert>}
      {banks.length === 0 && <Alert severity="info" sx={{ mb: 2 }}>Tag your bank accounts as “bank deposit” on the ledger roles page first.</Alert>}
      <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', mb: 2 }}>
        <TextField select size="small" label="Bank account" value={code} onChange={(e) => setCode(e.target.value)} sx={{ minWidth: 200 }}>
          {banks.map((b) => <MenuItem key={b} value={b}>{b}</MenuItem>)}
        </TextField>
        <Button variant="outlined" component="label" disabled={!code}>
          Import statement CSV
          <input hidden type="file" accept=".csv,text/csv" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) upload(f); }} />
        </Button>
        <TextField size="small" label="Statement closing balance" value={statementBalance} onChange={(e) => setStatementBalance(e.target.value)} />
        <Button variant="contained" disabled={!code || !statementBalance} onClick={reconcile}>Reconcile</Button>
      </Box>
      <Typography variant="caption" sx={{ display: 'block', mb: 2 }}>CSV columns: date (YYYY-MM-DD), description, amount (+ into the bank, − out), reference.</Typography>
      {recon && <>
        <Alert severity={recon.reconciled ? 'success' : 'error'} sx={{ mb: 2 }}>
          Ledger {recon.ledger_balance} → adjusted {recon.adjusted_ledger_balance} vs statement {recon.statement_balance}: {recon.reconciled ? 'reconciled' : 'difference to investigate'}
        </Alert>
        {list('In the ledger, not yet on the statement', recon.unmatched_ledger)}
        {list('On the statement, not yet in the ledger', recon.unmatched_statement)}
      </>}
      {sms && (
        <>
          <Typography variant="h6" component="h2" sx={{ mt: 4, mb: 1 }}>Member SMS (latest 100)</Typography>
          <Paper><Table size="small"><TableBody>
            {sms.length === 0 && <TableRow><TableCell>No messages.</TableCell></TableRow>}
            {sms.map((m) => <TableRow key={m.id}><TableCell>{m.created_at.slice(0, 16)}</TableCell><TableCell>{m.body}</TableCell><TableCell><Chip size="small" label={m.status} color={m.status === 'sent' ? 'success' : m.status === 'failed' ? 'error' : 'default'} /></TableCell></TableRow>)}
          </TableBody></Table></Paper>
        </>
      )}
    </Container>
  );
}
