'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Container from '@mui/material/Container';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { api, errorMessage, redirectForAuthError } from '@/lib/api';

type Kind = 'members' | 'history';
type Result = { ok: boolean; dry_run: boolean; lines: string[] };

const STEPS: { kind: Kind; title: string; help: string }[] = [
  { kind: 'members', title: '1. Members', help: 'members.csv — from migration:convert-excel, or the template in docs/migration.md.' },
  { kind: 'history', title: '2. Passbook history (backdated)', help: 'history.csv, and optionally expected_balances.csv so the import is refused unless every account ends on the old balance.' },
];

/** Settings → Import: upload the converted CSVs, check them (dry run), then import. Files are deleted on the server after each run. */
export default function ImportPage() {
  const router = useRouter();
  const [files, setFiles] = useState<Record<string, File | undefined>>({});
  const [busy, setBusy] = useState<Kind | null>(null);
  const [results, setResults] = useState<Partial<Record<Kind, Result>>>({});
  const [error, setError] = useState<string | null>(null);

  async function run(kind: Kind, dryRun: boolean) {
    const form = new FormData();
    form.append('file', files[kind]!);
    if (kind === 'history' && files.expected) form.append('expected', files.expected);
    form.append('dry_run', dryRun ? '1' : '0');
    setBusy(kind);
    setError(null);
    try {
      const { data } = await api<{ data: Result }>(`/imports/${kind}`, { form });
      setResults((r) => ({ ...r, [kind]: data }));
    } catch (e) {
      if (!redirectForAuthError(e, router.replace)) setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  const picker = (key: string, label: string) => (
    <Button variant="outlined" component="label" size="small">
      {files[key] ? `${label}: ${files[key]!.name}` : `Choose ${label}`}
      <input hidden type="file" accept=".csv,text/csv" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; setFiles((x) => ({ ...x, [key]: f })); setResults((r) => ({ ...r, [key === 'expected' ? 'history' : key]: undefined })); }} />
    </Button>
  );

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>Import from the previous system</Typography>
      <Alert severity="info" sx={{ mb: 2 }}>
        Convert the Excel passbook workbook first (migration:convert-excel, see docs/migration.md), then upload its CSV files here.
        Do not open a business day before the history is imported: history is replayed day by day from its first date.
      </Alert>
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      {STEPS.map(({ kind, title, help }) => {
        const result = results[kind];
        return (
          <Paper key={kind} sx={{ p: 3, mb: 2 }}>
            <Typography variant="h6" component="h2">{title}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{help}</Typography>
            <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center' }}>
              {picker(kind, kind === 'members' ? 'members.csv' : 'history.csv')}
              {kind === 'history' && picker('expected', 'expected_balances.csv')}
              <Button variant="outlined" disabled={!files[kind] || busy !== null} onClick={() => run(kind, true)}>Check (nothing is saved)</Button>
              <Button variant="contained" disabled={!files[kind] || busy !== null || !(result?.ok && result.dry_run)} onClick={() => run(kind, false)}>Import</Button>
            </Box>
            {busy === kind && <LinearProgress sx={{ mt: 2 }} aria-label="Running" />}
            {result && (
              <Alert severity={result.ok ? 'success' : 'error'} sx={{ mt: 2 }}>
                <strong>{result.ok ? (result.dry_run ? 'Check passed. You can import now.' : 'Imported.') : 'Not imported. Fix these and check again:'}</strong>
                <Box component="ul" sx={{ m: 0, pl: 2, maxHeight: 320, overflowY: 'auto' }}>
                  {result.lines.map((l, i) => <li key={i}>{l}</li>)}
                </Box>
              </Alert>
            )}
          </Paper>
        );
      })}
    </Container>
  );
}
