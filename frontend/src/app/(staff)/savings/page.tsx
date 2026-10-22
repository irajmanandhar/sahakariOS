'use client';

import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import { SEARCH_INPUT_ID } from '@/components/shell/GlobalSearch';
import StatusChip from '@/components/StatusChip';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import type { SavingsProduct } from '@/lib/types';

/** Savings home: how counter work starts (find the member), and the products on offer. */
export default function SavingsPage() {
  const t = useT();
  const products = useApi<SavingsProduct[]>('/savings-products');
  const columns: Column<SavingsProduct>[] = [
    { key: 'code', header: t('common.code'), mobile: 'title' },
    { key: 'name', header: t('common.name') },
    { key: 'type', header: t('common.type'), value: (p) => t(`savings.types.${p.type}`) },
    { key: 'interest_rate', header: t('savings.rate'), value: (p) => `${p.interest_rate}%`, align: 'right' },
    { key: 'interest_posting', header: t('savings.posting'), value: (p) => t(`savings.postings.${p.interest_posting as 'monthly'}`) },
    { key: 'minimum_balance', header: t('savings.minimumBalance'), money: true },
    { key: 'status', header: t('common.status'), render: (p) => <StatusChip kind="account" value={p.status} /> },
  ];

  return (
    <>
      <PageHeader title={t('nav.savings')} subtitle={t('savings.homeHelp')} />
      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 3, display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
        <Box sx={{ flexGrow: 1, minWidth: 240 }}>
          <Typography variant="h2" sx={{ mb: 0.5 }}>{t('savings.counterStart')}</Typography>
          <Typography variant="body2" color="text.secondary">{t('savings.counterStartHelp')}</Typography>
        </Box>
        <Button variant="contained" startIcon={<SearchOutlined />} onClick={() => document.getElementById(SEARCH_INPUT_ID)?.focus()}>{t('shell.searchLabel')}</Button>
      </Paper>
      <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
        <Typography variant="h2" sx={{ flexGrow: 1 }}>{t('nav.savingsProducts')}</Typography>
        <Button component={NextLink} href="/settings/savings-products">{t('savings.manageProducts')}</Button>
      </Box>
      <DataTable label={t('nav.savingsProducts')} columns={columns} rows={products.data ?? []} rowKey={(p) => p.id} loading={products.loading} error={products.error} onRetry={products.reload} emptyTitle={t('savings.noProducts')} />
      <GapNotice gap="G7">{t('savings.accountsGap')}</GapNotice>
    </>
  );
}
