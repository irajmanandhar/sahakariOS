'use client';

import PrintOutlined from '@mui/icons-material/PrintOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import TableCell from '@mui/material/TableCell';
import TableFooter from '@mui/material/TableFooter';
import TableRow from '@mui/material/TableRow';
import { useState } from 'react';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import { DateField } from '@/components/fields';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import ReportHeader from '@/components/ReportHeader';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { DayBook } from '@/lib/types';

type Day = DayBook['days'][number];

/**
 * Day book by product, like the old workbook's "Balance" sheet: per business date, credits, debits and
 * the closing balance of each savings product and of share capital (SHARE). At most 400 days.
 */
export default function DayBookPage() {
  const t = useT();
  const { businessDay } = useSession();
  const today = businessDay?.date ?? new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({ from: '', to: today });
  const [range, setRange] = useState<{ from: string; to: string } | null>(null);
  const book = useApi<DayBook>(range ? `/reports/daily-balances?from=${range.from}&to=${range.to}` : null);
  const d = book.data;

  const columns: Column<Day>[] = [
    { key: 'date', header: t('common.date'), mobile: 'title', render: (day) => <BsDate ad={day.date} bs={day.date_bs} /> },
    ...(d?.products ?? []).flatMap((p): Column<Day>[] => [
      { key: `${p}-credit`, header: `${p} · ${t('ledger.credit')}`, money: true, value: (day) => day.products[p]?.credit ?? '' },
      { key: `${p}-debit`, header: `${p} · ${t('ledger.debit')}`, money: true, value: (day) => day.products[p]?.debit ?? '' },
      { key: `${p}-balance`, header: `${p} · ${t('report.balance')}`, money: true, value: (day) => day.products[p]?.balance ?? '' },
    ]),
  ];

  const summaryRow = (label: string, values: Record<string, string>) => (
    <TableRow>
      <TableCell sx={{ fontWeight: 650, color: 'text.primary' }}>{label}</TableCell>
      {d?.products.map((p) => <TableCell key={p} colSpan={3} align="right"><MoneyText value={values[p] ?? '0.00'} strong /></TableCell>)}
    </TableRow>
  );

  return (
    <>
      <PageHeader
        title={t('nav.dayBook')}
        crumbs={[{ label: t('nav.reports'), href: '/reports' }]}
        subtitle={t('report.dayBookHelp')}
        actions={<Button startIcon={<PrintOutlined />} onClick={() => window.print()} disabled={!d}>{t('common.print')}</Button>}
      />
      <Box component="form" method="post" className="no-print" sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'flex-start', mb: 2 }}
        onSubmit={(e) => { e.preventDefault(); if (form.from && form.to) setRange(form); }}>
        <DateField label={t('common.from')} required value={form.from} onChange={(from) => setForm({ ...form, from })} />
        <DateField label={t('common.to')} required value={form.to} onChange={(to) => setForm({ ...form, to })} />
        <Button type="submit" variant="contained" disabled={!form.from || !form.to} sx={{ mt: 0.25 }}>{t('common.show')}</Button>
      </Box>
      {range && (
        <>
          <ReportHeader title={t('nav.dayBook')} from={range.from} to={range.to} />
          <DataTable
            label={t('nav.dayBook')}
            columns={columns}
            rows={d?.days ?? []}
            rowKey={(day) => day.date}
            loading={book.loading}
            error={book.error}
            onRetry={book.reload}
            emptyTitle={t('report.noMovements')}
            csvName={`day-book-${range.from}-${range.to}`}
            footer={d && (
              <TableFooter>
                {summaryRow(t('report.broughtForward'), d.opening)}
                {summaryRow(t('report.closing'), d.closing)}
              </TableFooter>
            )}
          />
        </>
      )}
    </>
  );
}
