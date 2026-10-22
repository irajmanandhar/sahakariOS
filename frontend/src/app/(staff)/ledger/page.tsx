'use client';

import PrintOutlined from '@mui/icons-material/PrintOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import TableCell from '@mui/material/TableCell';
import TableFooter from '@mui/material/TableFooter';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import DataTable, { type Column } from '@/components/DataTable';
import { DateField } from '@/components/fields';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import ReportHeader from '@/components/ReportHeader';
import StatusChip from '@/components/StatusChip';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import type { TrialBalance, TrialBalanceRow } from '@/lib/types';

/** Trial balance as of a date, summed from journal lines by the API. Amounts stay strings end to end. */
export default function TrialBalancePage() {
  const t = useT();
  const [asOf, setAsOf] = useState('');
  const [shown, setShown] = useState('');
  const tb = useApi<TrialBalance>(`/reports/trial-balance${shown ? `?as_of=${shown}` : ''}`);

  const columns: Column<TrialBalanceRow>[] = [
    { key: 'code', header: t('common.code'), mobile: 'title', noWrap: true },
    { key: 'name', header: t('ledger.account') },
    { key: 'type', header: t('common.type'), render: (r) => t(`ledger.types.${r.type}`) },
    { key: 'debit_balance', header: t('ledger.debit'), money: true },
    { key: 'credit_balance', header: t('ledger.credit'), money: true },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.trialBalance')}
        crumbs={[{ label: t('nav.ledger') }]}
        subtitle={t('ledger.tbHelp')}
        actions={<Button startIcon={<PrintOutlined />} onClick={() => window.print()}>{t('common.print')}</Button>}
      />
      <Box component="form" method="post" onSubmit={(e) => { e.preventDefault(); setShown(asOf); }} sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'flex-start', mb: 2 }} className="no-print">
        <DateField label={t('common.asOf')} value={asOf} onChange={setAsOf} />
        <Button type="submit" variant="contained" sx={{ mt: 0.25 }}>{t('common.show')}</Button>
        {shown && <Button sx={{ mt: 0.25 }} onClick={() => { setAsOf(''); setShown(''); }}>{t('ledger.latest')}</Button>}
      </Box>
      <ReportHeader title={t('nav.trialBalance')} asOf={tb.data?.as_of || shown || null} note={tb.data?.as_of ? undefined : t('ledger.allPostings')} />
      {tb.data && (
        <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap', mb: 1.5 }}>
          <StatusChip kind="balance" value={tb.data.balanced ? 'ok' : 'fail'} />
          <Typography variant="body2">{t('ledger.totalDebit')} <MoneyText value={tb.data.total_debit} strong /></Typography>
          <Typography variant="body2">{t('ledger.totalCredit')} <MoneyText value={tb.data.total_credit} strong /></Typography>
        </Box>
      )}
      <DataTable
        label={t('nav.trialBalance')}
        columns={columns}
        rows={tb.data?.accounts ?? []}
        rowKey={(r) => r.account_id}
        rowHref={(r) => `/ledger/accounts/${r.account_id}${shown ? `?as_of=${shown}` : ''}`}
        loading={tb.loading}
        error={tb.error}
        onRetry={tb.reload}
        emptyTitle={t('ledger.noPostings')}
        csvName={`trial-balance-${tb.data?.as_of ?? 'latest'}`}
        quickFilter
        footer={tb.data && (
          <TableFooter>
            <TableRow>
              <TableCell colSpan={3} sx={{ fontWeight: 650, color: 'text.primary' }}>{t('common.total')}</TableCell>
              <TableCell align="right"><MoneyText value={tb.data.total_debit} strong /></TableCell>
              <TableCell align="right"><MoneyText value={tb.data.total_credit} strong /></TableCell>
            </TableRow>
          </TableFooter>
        )}
      />
    </>
  );
}
