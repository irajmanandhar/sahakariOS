'use client';

import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import { ErrorState } from '@/components/States';
import { type MessageKey, useT } from '@/i18n';
import { api, redirectForAuthError } from '@/lib/api';
import type { ImportResult } from '@/lib/types';

type Kind = 'members' | 'history';
type RowError = { row: number; message: string };

const STEPS: { kind: Kind; title: MessageKey; help: MessageKey; file: string }[] = [
  { kind: 'members', title: 'import.membersTitle', help: 'import.membersHelp', file: 'members.csv' },
  { kind: 'history', title: 'import.historyTitle', help: 'import.historyHelp', file: 'history.csv' },
];

/** "Row 12: message" lines become a table; everything else stays a message. */
function split(lines: string[]): { rows: RowError[]; other: string[] } {
  const rows: RowError[] = [];
  const other: string[] = [];
  for (const line of lines) {
    const m = /^Row (\d+): (.*)$/.exec(line);
    if (m) rows.push({ row: Number(m[1]), message: m[2] });
    else other.push(line);
  }
  return { rows, other };
}

/**
 * Settings → Import: upload the converted CSVs, check them (dry run, nothing saved), then import.
 * Files are deleted on the server after each run; every check and import is in the audit log.
 */
export default function ImportPage() {
  const t = useT();
  const router = useRouter();
  const [files, setFiles] = useState<Record<string, File | undefined>>({});
  const [busy, setBusy] = useState<Kind | null>(null);
  const [results, setResults] = useState<Partial<Record<Kind, ImportResult>>>({});
  const [error, setError] = useState<unknown>();
  const [confirm, setConfirm] = useState<Kind | null>(null);

  async function run(kind: Kind, dryRun: boolean) {
    const form = new FormData();
    form.append('file', files[kind]!);
    if (kind === 'history' && files.expected) form.append('expected', files.expected);
    form.append('dry_run', dryRun ? '1' : '0');
    setBusy(kind);
    setError(undefined);
    try {
      const { data } = await api<{ data: ImportResult }>(`/imports/${kind}`, { form });
      setResults((r) => ({ ...r, [kind]: data }));
    } catch (e) {
      if (!redirectForAuthError(e, router.replace)) setError(e);
    } finally {
      setBusy(null);
    }
  }

  const picker = (key: string, label: string) => (
    <Button variant="outlined" component="label" startIcon={<UploadFileOutlined />}>
      {files[key] ? `${label}: ${files[key]!.name}` : t('import.choose', { file: label })}
      <input hidden type="file" accept=".csv,text/csv" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; setFiles((x) => ({ ...x, [key]: f })); setResults((r) => ({ ...r, [key === 'expected' ? 'history' : key]: undefined })); }} />
    </Button>
  );

  const columns: Column<RowError>[] = [
    { key: 'row', header: t('import.row'), width: 80, align: 'right' },
    { key: 'message', header: t('import.problem'), mobile: 'title' },
  ];

  return (
    <>
      <PageHeader title={t('import.title')} crumbs={[{ label: t('nav.settings') }]} subtitle={t('import.help')} />
      <Alert severity="warning" sx={{ mb: 3 }}>{t('import.noDayFirst')}</Alert>
      {error !== undefined && <ErrorState error={error} />}
      {STEPS.map(({ kind, title, help, file }) => {
        const result = results[kind];
        const { rows, other } = result ? split(result.lines) : { rows: [], other: [] };
        return (
          <Paper key={kind} component="section" aria-label={t(title)} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
            <Typography variant="h2">{t(title)}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t(help)}</Typography>
            <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'center' }}>
              {picker(kind, file)}
              {kind === 'history' && picker('expected', 'expected_balances.csv')}
              <Button variant="outlined" disabled={!files[kind] || busy !== null} onClick={() => run(kind, true)}>{t('import.check')}</Button>
              <Button variant="contained" disabled={!files[kind] || busy !== null || !(result?.ok && result.dry_run)} onClick={() => setConfirm(kind)}>{t('import.import')}</Button>
            </Box>
            {busy === kind && <LinearProgress sx={{ mt: 2 }} aria-label={t('import.running')} />}
            {result && (
              <Box sx={{ mt: 2 }}>
                <Alert severity={result.ok ? 'success' : 'error'} sx={{ mb: rows.length ? 2 : 0 }}>
                  <AlertTitle>{result.ok ? (result.dry_run ? t('import.checkPassed') : t('import.imported')) : t('import.failed', { count: rows.length || other.length })}</AlertTitle>
                  {other.length > 0 && <Box component="ul" sx={{ m: 0, pl: 2, maxHeight: 240, overflowY: 'auto' }}>{other.map((l, i) => <li key={i}>{l}</li>)}</Box>}
                </Alert>
                {rows.length > 0 && <DataTable label={t('import.rowErrors')} columns={columns} rows={rows} rowKey={(r) => `${r.row}-${r.message}`} csvName={`${kind}-check-errors`} quickFilter />}
              </Box>
            )}
          </Paper>
        );
      })}
      <ConfirmDialog
        open={confirm !== null}
        title={t('import.confirmTitle')}
        confirmLabel={t('import.import')}
        onClose={() => setConfirm(null)}
        onConfirm={async () => { if (confirm) await run(confirm, false); }}
      >
        {t('import.confirmBody', { file: confirm ? files[confirm]?.name ?? '' : '' })}
      </ConfirmDialog>
    </>
  );
}
