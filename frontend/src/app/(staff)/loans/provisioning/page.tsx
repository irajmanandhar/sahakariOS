'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { useState } from 'react';
import BsDate from '@/components/BsDate';
import ConfirmDialog from '@/components/ConfirmDialog';
import MoneyText, { useDigits } from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { Classification, ProvisioningResult, Report } from '@/lib/types';

const CLASSES: Classification[] = ['pass', 'substandard', 'doubtful', 'loss'];

/**
 * Loan classification (NCRA Std 3/3) and loan-loss provision (Std 3/4.1). Classification runs at every
 * day close; provision changes post at BS month ends, or now with "Classify and post provision".
 */
export default function ProvisioningPage() {
  const t = useT();
  const toast = useToast();
  const digits = useDigits();
  const { can, businessDay } = useSession();
  const report = useApi<Report>(can('report.view') ? '/reports/statements/loan_classification' : null);
  const [confirm, setConfirm] = useState(false);
  const [result, setResult] = useState<ProvisioningResult | null>(null);

  return (
    <>
      <PageHeader
        title={t('nav.provisioning')}
        crumbs={[{ label: t('nav.loans'), href: '/loans' }]}
        subtitle={t('loans.provisionHelp')}
        actions={<>
          {can('report.view') && <Button component={NextLink} href="/reports/loan_classification">{t('loans.fullReport')}</Button>}
          {can('loan.provision') && <Button variant="contained" disabled={!businessDay} onClick={() => setConfirm(true)}>{t('loans.runProvision')}</Button>}
        </>}
      />
      {result && (
        <Alert severity="success" sx={{ mb: 3 }} onClose={() => setResult(null)}>
          <Typography sx={{ fontWeight: 650 }}>{t('loans.provisionDone')} <BsDate ad={result.date} /></Typography>
          <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', my: 1 }}>
            {CLASSES.map((c) => <Box key={c} sx={{ display: 'flex', gap: 0.5, alignItems: 'center' }}><StatusChip kind="classification" value={c} /> {digits(result.counts[c] ?? 0)}</Box>)}
          </Box>
          {t('loans.required')} <MoneyText value={result.required_provision} prefix strong /> · {t('loans.previous')} <MoneyText value={result.previous_provision} prefix /> · {t('loans.change')} <MoneyText value={result.change} prefix strong />
        </Alert>
      )}
      {!can('report.view') ? <Alert severity="info">{t('dashboard.noReportAccess')}</Alert>
        : report.error !== undefined ? <ErrorState error={report.error} onRetry={report.reload} />
        : !report.data ? <LoadingBlock rows={6} /> : (
          <>
            {report.data.checks.map((c) => (
              <Alert key={c.label} severity={c.ok ? 'success' : 'error'} sx={{ mb: 2 }}>{c.label}: <strong>{c.value}</strong> ({c.requirement}; {c.source})</Alert>
            ))}
            {report.data.sections.map((s) => (
              <Paper key={s.title} sx={{ p: 2, mb: 2 }}>
                <Typography variant="h3" component="h2" sx={{ mb: 1 }}>{s.title}</Typography>
                <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: '1fr auto auto', columnGap: 2, rowGap: 0.75, typography: 'body2' }}>
                  {s.rows.map((r) => (
                    <Box key={r.label} sx={{ display: 'contents' }}>
                      <dt>{r.label}</dt>
                      <Typography component="dd" variant="body2" color="text.secondary" sx={{ m: 0 }}>{r.note ? digits(r.note) : ''}</Typography>
                      <Box component="dd" sx={{ m: 0, textAlign: 'right' }}><MoneyText value={r.amount} /></Box>
                    </Box>
                  ))}
                  {s.total !== undefined && <Box sx={{ display: 'contents', fontWeight: 650 }}><dt>{t('common.total')}</dt><dd /><Box component="dd" sx={{ m: 0, textAlign: 'right' }}><MoneyText value={s.total} strong /></Box></Box>}
                </Box>
              </Paper>
            ))}
          </>
        )}
      <ConfirmDialog open={confirm} title={t('loans.runProvisionTitle')} confirmLabel={t('loans.runProvision')} onClose={() => setConfirm(false)}
        onConfirm={async () => {
          const { data } = await api<{ data: ProvisioningResult }>('/loan-provisioning', { method: 'POST' });
          setResult(data);
          toast.success(t('loans.provisionDone'));
          report.reload();
        }}>
        {t('loans.runProvisionBody')}
      </ConfirmDialog>
    </>
  );
}
