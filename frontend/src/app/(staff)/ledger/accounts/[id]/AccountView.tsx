'use client';

import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { useParams, useSearchParams } from 'next/navigation';
import BsDate from '@/components/BsDate';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, GapNotice, LoadingBlock } from '@/components/States';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import type { LedgerAccount, TrialBalance } from '@/lib/types';

/**
 * A ledger account and its balance (from the trial balance). The account's journal lines need an
 * endpoint the API does not have yet (gap G3), so the drill-down stops here with a clear notice.
 */
export default function AccountView() {
  const t = useT();
  const { id } = useParams<{ id: string }>();
  const asOf = useSearchParams().get('as_of') ?? '';
  const accounts = useApi<LedgerAccount[]>('/ledger-accounts');
  const tb = useApi<TrialBalance>(`/reports/trial-balance${asOf ? `?as_of=${asOf}` : ''}`);
  const account = accounts.data?.find((a) => String(a.id) === id);
  const parent = accounts.data?.find((a) => a.id === account?.parent_id);
  const balance = tb.data?.accounts.find((a) => String(a.account_id) === id);

  if (accounts.error !== undefined) return <ErrorState error={accounts.error} onRetry={accounts.reload} />;
  if (!accounts.data) return <LoadingBlock />;
  if (!account) return <ErrorState error={new Error(t('errors.notFound'))} />;

  const fact = (label: string, value: React.ReactNode) => (
    <Grid size={{ xs: 12, sm: 6, md: 3 }}>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography component="div">{value}</Typography>
    </Grid>
  );

  return (
    <>
      <PageHeader
        title={<>{account.code} {account.name} <StatusChip kind="account" value={account.status} /></>}
        crumbs={[{ label: t('nav.ledger'), href: '/ledger' }, { label: t('nav.chartOfAccounts'), href: '/ledger/accounts' }]}
      />
      <Paper sx={{ p: 3, mb: 3 }}>
        <Grid container spacing={2}>
          {fact(t('common.type'), t(`ledger.types.${account.type}`))}
          {fact(t('ledger.normalSide'), t(account.normal_side === 'debit' ? 'ledger.debit' : 'ledger.credit'))}
          {fact(t('ledger.parent'), parent ? `${parent.code} ${parent.name}` : '—')}
          {fact(t('ledger.postable'), account.is_postable ? t('common.yes') : t('ledger.group'))}
          {fact(t('ledger.debitBalance'), tb.loading ? '…' : <MoneyText value={balance?.debit_balance ?? '0.00'} />)}
          {fact(t('ledger.creditBalance'), tb.loading ? '…' : <MoneyText value={balance?.credit_balance ?? '0.00'} />)}
          {fact(t('common.asOf'), asOf ? <BsDate ad={asOf} /> : t('ledger.allPostings'))}
        </Grid>
      </Paper>
      <GapNotice gap="G3">{t('ledger.accountLinesGap')}</GapNotice>
      <Box sx={{ display: 'flex', gap: 1 }}>
        <Button component={NextLink} href="/ledger/journal">{t('nav.journal')}</Button>
        <Button component={NextLink} href="/ledger">{t('nav.trialBalance')}</Button>
      </Box>
    </>
  );
}
