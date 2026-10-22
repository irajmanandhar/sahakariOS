'use client';

import PrintOutlined from '@mui/icons-material/PrintOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { downloadCsv } from '@/components/DataTable';
import { DateField } from '@/components/fields';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import ReportHeader from '@/components/ReportHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, LoadingBlock } from '@/components/States';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { LedgerAccount, Report } from '@/lib/types';

const MONEY = /^-?\d+(\.\d+)?$/;

/**
 * One statement or NCRA return, printable. Rows that name a ledger account ("1.1 Cash") link to that
 * account (the next step of the drill-down). Amounts are shown exactly as the API computed them.
 */
export default function ReportView() {
  const t = useT();
  const router = useRouter();
  const { can } = useSession();
  const { code } = useParams<{ code: string }>();
  const params = useSearchParams();
  const asOf = params.get('as_of') ?? '';
  const from = params.get('from') ?? '';
  const [form, setForm] = useState({ asOf, from });
  const query = new URLSearchParams({ ...(asOf && { as_of: asOf }), ...(from && { from }) }).toString();
  const report = useApi<Report>(`/reports/statements/${code}${query ? `?${query}` : ''}`);
  const accounts = useApi<LedgerAccount[]>(can('ledger.view') ? '/ledger-accounts' : null);
  const byCode = new Map((accounts.data ?? []).map((a) => [a.code, a]));
  const r = report.data;
  const periodic = code === 'profit_and_loss';

  const label = (text: string) => {
    const account = byCode.get(text.split(' ')[0]);
    if (!account) return text;
    return <Link component={NextLink} href={`/ledger/accounts/${account.id}${r?.as_of ?? r?.to ? `?as_of=${r?.as_of ?? r?.to}` : ''}`} underline="hover">{text}</Link>;
  };

  const exportCsv = () => r && downloadCsv(
    `${code}-${r.as_of ?? r.to ?? 'latest'}`,
    [t('report.section'), t('report.line'), t('common.amount'), t('report.note')],
    r.sections.flatMap((s) => [...s.rows.map((row) => [s.title, row.label, row.amount, row.note ?? '']), ...(s.total !== undefined ? [[s.title, t('common.total'), s.total, '']] : [])]),
  );

  return (
    <>
      <PageHeader
        title={r?.title ?? t('nav.reports')}
        crumbs={[{ label: t('nav.reports'), href: '/reports' }]}
        meta={<Chip variant="outlined" color="warning" label={t('common.proposed')} title={t('common.proposedHelp')} />}
        actions={<>
          <Button onClick={exportCsv} disabled={!r}>{t('common.exportCsv')}</Button>
          <Button startIcon={<PrintOutlined />} onClick={() => window.print()} disabled={!r}>{t('common.print')}</Button>
        </>}
      />
      <Box component="form" method="post" className="no-print" sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'flex-start', mb: 2 }}
        onSubmit={(e) => {
          e.preventDefault();
          const q = new URLSearchParams({ ...(form.asOf && { as_of: form.asOf }), ...(periodic && form.from && { from: form.from }) }).toString();
          router.replace(`/reports/${code}${q ? `?${q}` : ''}`);
        }}>
        {periodic && <DateField label={t('common.from')} value={form.from} onChange={(v) => setForm({ ...form, from: v })} />}
        <DateField label={periodic ? t('common.to') : t('common.asOf')} value={form.asOf} onChange={(v) => setForm({ ...form, asOf: v })} />
        <Button type="submit" variant="contained" sx={{ mt: 0.25 }}>{t('common.show')}</Button>
      </Box>

      {report.error !== undefined && <ErrorState error={report.error} onRetry={report.reload} />}
      {report.loading && <LoadingBlock rows={8} />}
      {r && !report.loading && (
        <>
          <ReportHeader title={r.title} asOf={r.as_of} from={r.from} to={r.to} note={t('report.computedLive')} />
          {r.checks.length > 0 && (
            <Box component="section" aria-label={t('report.checks')} sx={{ display: 'grid', gap: 1, mb: 3 }}>
              {r.checks.map((c) => (
                <Alert key={c.label} severity={c.ok ? 'success' : 'error'} icon={false} sx={{ alignItems: 'center' }}>
                  <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center', flexWrap: 'wrap' }}>
                    <StatusChip kind="check" value={c.ok ? 'ok' : 'fail'} />
                    <span>{c.label}: <strong>{c.value}</strong></span>
                    <Typography variant="body2" color="text.secondary">{t('report.requirement', { requirement: c.requirement, source: c.source })}</Typography>
                  </Box>
                </Alert>
              ))}
            </Box>
          )}
          {r.sections.map((s) => (
            <Paper key={s.title} component="section" aria-label={s.title} sx={{ mb: 2, overflow: 'hidden', breakInside: 'avoid' }}>
              <Typography variant="h3" component="h2" sx={{ px: 2, pt: 1.5, pb: 0.5 }}>{s.title}</Typography>
              <Box sx={{ overflowX: 'auto' }}>
                <Table size="small">
                  <TableBody>
                    {s.rows.length === 0 && <TableRow><TableCell colSpan={3} sx={{ color: 'text.secondary' }}>{t('common.none')}</TableCell></TableRow>}
                    {s.rows.map((row, i) => (
                      <TableRow key={`${row.label}-${i}`}>
                        <TableCell>{label(row.label)}</TableCell>
                        <TableCell align="right" sx={{ whiteSpace: 'nowrap', width: '1%' }}>{MONEY.test(row.amount) ? <MoneyText value={row.amount} /> : row.amount}</TableCell>
                        <TableCell sx={{ color: 'text.secondary', width: '30%' }}>{row.note}</TableCell>
                      </TableRow>
                    ))}
                    {s.total !== undefined && (
                      <TableRow>
                        <TableCell sx={{ fontWeight: 650 }}>{t('common.total')}</TableCell>
                        <TableCell align="right"><MoneyText value={s.total} strong /></TableCell>
                        <TableCell />
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </Box>
            </Paper>
          ))}
          <Typography variant="caption" color="text.secondary">{t('report.drillHelp')}</Typography>
        </>
      )}
    </>
  );
}
