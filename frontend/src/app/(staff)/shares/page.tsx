'use client';

import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import PageHeader from '@/components/PageHeader';
import { SEARCH_INPUT_ID } from '@/components/shell/GlobalSearch';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { useSession } from '@/lib/session';

/** Share capital (Act s.63): purchases are made from the member's page; the cooperative-wide register needs an API (G7). */
export default function SharesPage() {
  const t = useT();
  const { can } = useSession();
  return (
    <>
      <PageHeader title={t('nav.shares')} subtitle={t('shares.help')} />
      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 3, display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
        <Box sx={{ flexGrow: 1, minWidth: 240 }}>
          <Typography variant="h2" sx={{ mb: 0.5 }}>{t('shares.buyTitle')}</Typography>
          <Typography variant="body2" color="text.secondary">{t('shares.buyHelp')}</Typography>
        </Box>
        <Button variant="contained" startIcon={<SearchOutlined />} onClick={() => document.getElementById(SEARCH_INPUT_ID)?.focus()}>{t('shell.searchLabel')}</Button>
      </Paper>
      <GapNotice gap="G7">{t('shares.registerGap')}</GapNotice>
      {can('report.view') && <Button component={NextLink} href="/reports/day-book">{t('shares.dayBookLink')}</Button>}
    </>
  );
}
