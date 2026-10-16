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
import { api, errorMessage, redirectForAuthError } from '@/lib/api';

type Row = { label: string; amount: string; note?: string };
type Report = { code: string; title: string; as_of?: string; from?: string; to?: string; sections: { title: string; rows: Row[]; total?: string }[]; checks: { label: string; value: string; requirement: string; ok: boolean; source: string }[] };
type Ttr = { business_date: string; report_by: string; member_number: number; full_name: string; direction: string; total: string; transactions: number };

const TTR = 'threshold_transactions';

/** Statements and NCRA returns, computed live from the ledger. Formats are PROPOSED until NCRA's templates are obtained. */
export default function ReportsPage() {
  const router = useRouter();
  const [list, setList] = useState<{ code: string; title: string }[]>([]);
  const [code, setCode] = useState('balance_sheet');
  const [asOf, setAsOf] = useState('');
  const [report, setReport] = useState<Report | null>(null);
  const [ttr, setTtr] = useState<Ttr[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const fail = useCallback((e: unknown) => redirectForAuthError(e, router.replace) || setError(errorMessage(e)), [router]);
  useEffect(() => {
    api<{ data: { code: string; title: string }[] }>('/reports').then((r) => setList([...r.data, { code: TTR, title: 'Threshold transactions to report to FIU (AML Instruction 12)' }])).catch(fail);
  }, [fail]);

  async function run() {
    setError(null);
    setReport(null);
    setTtr(null);
    try {
      if (code === TTR) {
        setTtr((await api<{ data: Ttr[] }>('/reports/threshold-transactions')).data);
      } else {
        setReport((await api<{ data: Report }>(`/reports/statements/${code}${asOf ? `?as_of=${asOf}` : ''}`)).data);
      }
    } catch (e) {
      fail(e);
    }
  }

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>Reports</Typography>
      <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', mb: 2 }}>
        <TextField select size="small" label="Report" value={code} onChange={(e) => setCode(e.target.value)} sx={{ minWidth: 360 }}>
          {list.map((r) => <MenuItem key={r.code} value={r.code}>{r.title}</MenuItem>)}
        </TextField>
        {code !== TTR && <TextField size="small" type="date" label="As of (AD)" value={asOf} onChange={(e) => setAsOf(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />}
        <Button variant="contained" onClick={run}>Show</Button>
      </Box>
      {error && <Alert severity="error">{error}</Alert>}

      {report && (
        <>
          <Typography variant="h6" component="h2">{report.title}</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            {report.as_of && `As of ${report.as_of}`}{report.from && `${report.from} to ${report.to}`}
          </Typography>
          {report.checks.map((c) => (
            <Alert key={c.label} severity={c.ok ? 'success' : 'error'} sx={{ mb: 1 }}>
              {c.label}: <strong>{c.value}</strong> (requirement {c.requirement}; {c.source})
            </Alert>
          ))}
          {report.sections.map((s) => (
            <Paper key={s.title} sx={{ mb: 2, overflowX: 'auto' }}>
              <Typography variant="subtitle1" sx={{ p: 2, pb: 0 }}>{s.title}</Typography>
              <Table size="small">
                <TableBody>
                  {s.rows.length === 0 && <TableRow><TableCell colSpan={3}>—</TableCell></TableRow>}
                  {s.rows.map((r, i) => (
                    <TableRow key={`${r.label}-${i}`}><TableCell>{r.label}</TableCell><TableCell align="right">{r.amount}</TableCell><TableCell>{r.note}</TableCell></TableRow>
                  ))}
                  {s.total !== undefined && <TableRow><TableCell><strong>Total</strong></TableCell><TableCell align="right"><strong>{s.total}</strong></TableCell><TableCell /></TableRow>}
                </TableBody>
              </Table>
            </Paper>
          ))}
        </>
      )}

      {ttr && (
        <Paper sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableBody>
              {ttr.length === 0 && <TableRow><TableCell>No threshold transactions in the last 30 days.</TableCell></TableRow>}
              {ttr.map((t) => (
                <TableRow key={`${t.business_date}-${t.member_number}-${t.direction}`}>
                  <TableCell>{t.business_date}</TableCell><TableCell>No. {t.member_number} {t.full_name}</TableCell>
                  <TableCell><Chip size="small" label={t.direction.replace('_', ' ')} /></TableCell>
                  <TableCell align="right">Rs {t.total} ({t.transactions} txn)</TableCell><TableCell>report by {t.report_by}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}
    </Container>
  );
}
