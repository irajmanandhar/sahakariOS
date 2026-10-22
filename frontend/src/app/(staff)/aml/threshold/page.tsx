'use client';

import PrintOutlined from '@mui/icons-material/PrintOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import { useState } from 'react';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import { DateField } from '@/components/fields';
import PageHeader from '@/components/PageHeader';
import ReportHeader from '@/components/ReportHeader';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { ThresholdTransaction } from '@/lib/types';

/** Cash of Rs 10 lakh or more in, or out, per member per day, to report to FIU-Nepal within 15 days (AML Instruction 12). */
export default function ThresholdPage() {
  const t = useT();
  const { businessDay } = useSession();
  const [form, setForm] = useState({ from: '', to: '' });
  const [range, setRange] = useState({ from: '', to: '' });
  const query = new URLSearchParams({ ...(range.from && { from: range.from }), ...(range.to && { to: range.to }) }).toString();
  const list = useApi<ThresholdTransaction[]>(`/reports/threshold-transactions${query ? `?${query}` : ''}`);
  const today = businessDay?.date ?? '';

  const columns: Column<ThresholdTransaction>[] = [
    { key: 'business_date', header: t('common.date'), render: (r) => <BsDate ad={r.business_date} /> },
    { key: 'member', header: t('aml.member'), mobile: 'title', value: (r) => `${r.member_number} ${r.full_name}` },
    { key: 'direction', header: t('aml.direction'), render: (r) => <Chip variant="outlined" label={r.direction === 'cash_in' ? t('aml.cashIn') : r.direction === 'cash_out' ? t('aml.cashOut') : r.direction} /> },
    { key: 'total', header: t('common.amountRs'), money: true },
    { key: 'transactions', header: t('aml.transactions'), align: 'right' },
    {
      key: 'report_by', header: t('aml.reportBy'),
      render: (r) => <Box component="span" sx={{ color: today && r.report_by < today ? 'error.main' : undefined, fontWeight: today && r.report_by <= today ? 650 : undefined }}><BsDate ad={r.report_by} /></Box>,
    },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.threshold')}
        crumbs={[{ label: t('nav.aml') }]}
        subtitle={t('aml.thresholdHelp')}
        actions={<Button startIcon={<PrintOutlined />} onClick={() => window.print()}>{t('common.print')}</Button>}
      />
      <Box component="form" method="post" className="no-print" sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'flex-start', mb: 2 }}
        onSubmit={(e) => { e.preventDefault(); setRange(form); }}>
        <DateField label={t('common.from')} value={form.from} onChange={(from) => setForm({ ...form, from })} helperText={t('aml.defaultRange')} />
        <DateField label={t('common.to')} value={form.to} onChange={(to) => setForm({ ...form, to })} />
        <Button type="submit" variant="contained" sx={{ mt: 0.25 }}>{t('common.show')}</Button>
      </Box>
      <ReportHeader title={t('nav.threshold')} from={range.from || null} to={range.to || null} note={range.from ? undefined : t('aml.last30')} />
      <DataTable
        label={t('nav.threshold')}
        columns={columns}
        rows={list.data ?? []}
        rowKey={(r) => `${r.business_date}-${r.member_number}-${r.direction}`}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        emptyTitle={t('aml.noThreshold')}
        csvName={`threshold-transactions-${range.from || 'last-30-days'}`}
        quickFilter
      />
    </>
  );
}
