'use client';

import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, GapNotice, LoadingBlock } from '@/components/States';
import { useT } from '@/i18n';
import { sequenceGaps } from '@/lib/audit';
import { useApi, useCursorList } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { AuditLog, TrialBalance } from '@/lib/types';

function Check({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Paper sx={{ p: 2.5, mb: 2 }}>
      <Typography variant="h2" sx={{ mb: 1 }}>{title}</Typography>
      {children}
    </Paper>
  );
}

/**
 * What the browser can check through the API (trial balance balances, audit sequence has no gaps)
 * next to the server-side commands that prove integrity (ledger:verify, audit:verify).
 */
export default function IntegrityPage() {
  const t = useT();
  const { can } = useSession();
  const tb = useApi<TrialBalance>(can('ledger.view') ? '/reports/trial-balance' : null);
  const audit = useCursorList<AuditLog>(can('audit_log.view') ? '/audit-logs' : null);
  const gaps = sequenceGaps(audit.rows);

  return (
    <>
      <PageHeader title={t('nav.integrity')} crumbs={[{ label: t('nav.audit') }]} subtitle={t('integrity.help')} />
      <Check title={t('integrity.serverTitle')}>
        <Typography variant="body2" sx={{ mb: 1 }}>{t('integrity.serverBody')}</Typography>
        <Box component="pre" sx={{ m: 0, p: 1.5, bgcolor: 'action.hover', borderRadius: 1, overflowX: 'auto', fontSize: '0.8125rem' }}>
          php artisan ledger:verify{'\n'}php artisan audit:verify
        </Box>
        <GapNotice gap="G2">{t('integrity.gap')}</GapNotice>
      </Check>
      {can('ledger.view') && (
        <Check title={t('integrity.tbTitle')}>
          {tb.error !== undefined ? <ErrorState error={tb.error} onRetry={tb.reload} /> : !tb.data ? <LoadingBlock rows={1} /> : (
            <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
              <StatusChip kind="balance" value={tb.data.balanced ? 'ok' : 'fail'} />
              <Typography variant="body2">{t('ledger.totalDebit')} <MoneyText value={tb.data.total_debit} strong /></Typography>
              <Typography variant="body2">{t('ledger.totalCredit')} <MoneyText value={tb.data.total_credit} strong /></Typography>
            </Box>
          )}
        </Check>
      )}
      {can('audit_log.view') && (
        <Check title={t('integrity.auditTitle')}>
          {audit.error !== undefined ? <ErrorState error={audit.error} onRetry={audit.reload} /> : audit.loading && audit.rows.length === 0 ? <LoadingBlock rows={1} /> : (
            <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
              <StatusChip kind="check" value={gaps.length === 0 ? 'ok' : 'fail'} />
              <Typography variant="body2">
                {audit.rows.length === 0 ? t('audit.empty') : gaps.length === 0
                  ? t('audit.sequenceOk', { first: audit.rows[audit.rows.length - 1].sequence, last: audit.rows[0].sequence })
                  : t('audit.sequenceGap', { gaps: gaps.map(([a, b]) => `${a}–${b}`).join(', ') })}
              </Typography>
            </Box>
          )}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>{t('integrity.auditNote')}</Typography>
        </Check>
      )}
    </>
  );
}
