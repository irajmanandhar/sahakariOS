'use client';

import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import { useT } from '@/i18n';
import { fiscalYearOf, nepalDateTime } from '@/lib/bs';
import { useSession } from '@/lib/session';
import { useBsText } from './BsDate';

/**
 * Document header for printable reports and exports: cooperative, report title, period in BS
 * with AD, fiscal year, and who generated it when. Printed on every report page.
 */
export default function ReportHeader({ title, asOf, from, to, note }: { title: string; asOf?: string | null; from?: string | null; to?: string | null; note?: string }) {
  const t = useT();
  const bs = useBsText();
  const { membership, user } = useSession();
  const [generated] = useState(() => nepalDateTime(new Date().toISOString()));
  const date = (ad: string) => `${bs(ad, null, 'long') ?? ad} (${ad} ${t('common.ad')})`;
  const period = from && to ? t('report.period', { from: date(from), to: date(to) }) : asOf ? t('report.asOf', { date: date(asOf) }) : null;
  const fy = fiscalYearOf(asOf || to || generated?.date || '');

  return (
    <Box component="section" aria-label={t('report.header')} sx={{ mb: 3, pb: 2, borderBottom: 2, borderColor: 'text.primary', '@media print': { mb: 2 } }}>
      <Typography variant="subtitle2" color="text.secondary">{membership.cooperative.name} · {membership.branch.name}</Typography>
      <Typography variant="h2" component="p" sx={{ mt: 0.25 }}>{title}</Typography>
      <Box sx={{ display: 'flex', gap: 3, flexWrap: 'wrap', mt: 1, typography: 'body2' }}>
        {period && <span>{period}</span>}
        {fy && <span>{t('shell.fiscalYear', { fy })}</span>}
        {note && <span>{note}</span>}
      </Box>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.5 }}>
        {t('report.generated', { name: user.name, date: generated ? `${bs(generated.date) ?? generated.date} ${generated.time}` : '' })}
      </Typography>
    </Box>
  );
}
