'use client';

import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Container from '@mui/material/Container';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { api, errorMessage, redirectForAuthError } from '@/lib/api';

type TrialBalanceRow = { account_id: number; code: string; name: string; type: string; debit_balance: string; credit_balance: string };
type TrialBalance = { accounts: TrialBalanceRow[]; total_debit: string; total_credit: string; balanced: boolean };
type Entry = { id: number; reference: string; entry_date: string; entry_date_bs: string; description: string; total: string; reversal_of_id: number | null };

/** Read-only ledger view. Amounts stay strings end to end (no floating point). */
export default function LedgerPage() {
  const router = useRouter();
  const [tb, setTb] = useState<TrialBalance | null>(null);
  const [entries, setEntries] = useState<Entry[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([
      api<{ data: TrialBalance }>('/reports/trial-balance'),
      api<{ data: Entry[] }>('/journal-entries'),
    ])
      .then(([t, e]) => {
        setTb(t.data);
        setEntries(e.data);
      })
      .catch((err) => redirectForAuthError(err, router.replace) || setError(errorMessage(err)));
  }, [router]);

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>
        Ledger
      </Typography>
      {error && <Alert severity="error">{error}</Alert>}
      {!tb && !error && <Typography>Loading…</Typography>}
      {tb && (
        <Paper sx={{ mb: 4, overflowX: 'auto' }}>
          <Typography variant="h6" component="h2" sx={{ p: 2 }}>
            Trial balance{' '}
            <Chip size="small" color={tb.balanced ? 'success' : 'error'} label={tb.balanced ? 'Balanced' : 'NOT balanced'} />
          </Typography>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Code</TableCell>
                <TableCell>Account</TableCell>
                <TableCell align="right">Debit</TableCell>
                <TableCell align="right">Credit</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {tb.accounts.length === 0 && (
                <TableRow>
                  <TableCell colSpan={4}>No postings yet.</TableCell>
                </TableRow>
              )}
              {tb.accounts.map((a) => (
                <TableRow key={a.account_id}>
                  <TableCell>{a.code}</TableCell>
                  <TableCell>{a.name}</TableCell>
                  <TableCell align="right">{a.debit_balance}</TableCell>
                  <TableCell align="right">{a.credit_balance}</TableCell>
                </TableRow>
              ))}
              <TableRow>
                <TableCell colSpan={2}>
                  <strong>Total</strong>
                </TableCell>
                <TableCell align="right">
                  <strong>{tb.total_debit}</strong>
                </TableCell>
                <TableCell align="right">
                  <strong>{tb.total_credit}</strong>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </Paper>
      )}
      {tb && (
        <Paper sx={{ overflowX: 'auto' }}>
          <Typography variant="h6" component="h2" sx={{ p: 2 }}>
            Recent journal entries
          </Typography>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Reference</TableCell>
                <TableCell>Date (BS)</TableCell>
                <TableCell>Description</TableCell>
                <TableCell align="right">Amount</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {entries.length === 0 && (
                <TableRow>
                  <TableCell colSpan={4}>No journal entries yet.</TableCell>
                </TableRow>
              )}
              {entries.map((e) => (
                <TableRow key={e.id}>
                  <TableCell>
                    {e.reference} {e.reversal_of_id && <Chip size="small" label="reversal" />}
                  </TableCell>
                  <TableCell>{e.entry_date_bs}</TableCell>
                  <TableCell>{e.description}</TableCell>
                  <TableCell align="right">{e.total}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}
    </Container>
  );
}
