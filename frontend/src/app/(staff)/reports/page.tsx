'use client';

import ArrowForward from '@mui/icons-material/ArrowForward';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Grid from '@mui/material/Grid';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import PageHeader from '@/components/PageHeader';
import { ErrorState, LoadingBlock } from '@/components/States';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';

function Card({ href, title, body, proposed }: { href: string; title: string; body?: string; proposed?: boolean }) {
  const t = useT();
  return (
    <Paper component={NextLink} href={href} sx={{ p: 2.5, display: 'flex', flexDirection: 'column', gap: 1, height: '100%', textDecoration: 'none', color: 'inherit', '&:hover, &:focus-visible': { borderColor: 'primary.main' } }}>
      <Typography variant="h3" component="h2">{title}</Typography>
      {body && <Typography variant="body2" color="text.secondary">{body}</Typography>}
      <Box sx={{ mt: 'auto', display: 'flex', alignItems: 'center', gap: 1 }}>
        {proposed && <Chip variant="outlined" color="warning" label={t('common.proposed')} title={t('common.proposedHelp')} />}
        <Box sx={{ flexGrow: 1 }} />
        <ArrowForward fontSize="small" color="action" aria-hidden />
      </Box>
    </Paper>
  );
}

/** Statements and NCRA returns, computed live from journal lines. Formats are PROPOSED until NCRA's templates are obtained. */
export default function ReportsPage() {
  const t = useT();
  const { can } = useSession();
  const list = useApi<{ code: string; title: string }[]>('/reports');

  return (
    <>
      <PageHeader title={t('nav.statements')} crumbs={[{ label: t('nav.reports') }]} subtitle={t('report.indexHelp')} />
      {list.error !== undefined && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.loading && <LoadingBlock rows={3} />}
      <Grid container spacing={2}>
        {list.data?.map((r) => (
          <Grid key={r.code} size={{ xs: 12, sm: 6, lg: 4 }}><Card href={`/reports/${r.code}`} title={r.title} proposed /></Grid>
        ))}
        <Grid size={{ xs: 12, sm: 6, lg: 4 }}><Card href="/reports/day-book" title={t('nav.dayBook')} body={t('report.dayBookHelp')} /></Grid>
        {can('ledger.view') && <Grid size={{ xs: 12, sm: 6, lg: 4 }}><Card href="/ledger" title={t('nav.trialBalance')} body={t('ledger.tbHelp')} /></Grid>}
        {can('aml.view') && <Grid size={{ xs: 12, sm: 6, lg: 4 }}><Card href="/aml/threshold" title={t('nav.threshold')} body={t('aml.thresholdHelp')} /></Grid>}
      </Grid>
    </>
  );
}
