'use client';

import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import { DateField } from '@/components/fields';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import ReportHeader from '@/components/ReportHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api, redirectForAuthError } from '@/lib/api';
import { useApi } from '@/lib/hooks';
import { useRouter } from 'next/navigation';
import type { LedgerTags, Reconciliation } from '@/lib/types';

type Item = Reconciliation['unmatched_ledger'][number];
const SIGNED = /^-?\d{1,16}(\.\d{1,2})?$/;

/** Bank statement import and reconciliation against the ledger account tagged as a bank deposit. */
export default function BankingPage() {
  const t = useT();
  const toast = useToast();
  const router = useRouter();
  const tags = useApi<LedgerTags>('/ledger-account-tags');
  const banks = [...new Set((tags.data?.assigned ?? []).filter((a) => a.tag.endsWith('bank_deposit')).map((a) => a.code))];
  const [code, setCode] = useState('');
  const [balance, setBalance] = useState('');
  const [asOf, setAsOf] = useState('');
  const [recon, setRecon] = useState<Reconciliation | null>(null);
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);

  const fail = (e: unknown) => redirectForAuthError(e, router.replace) || setError(e);

  async function upload(file: File) {
    const form = new FormData();
    form.append('file', file);
    setBusy(true);
    setError(undefined);
    try {
      const { data } = await api<{ data: { imported: number; matched: number } }>(`/bank-accounts/${encodeURIComponent(code)}/statements`, { form });
      toast.success(t('banking.imported', { imported: data.imported, matched: data.matched }));
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }

  async function reconcile(e: FormEvent) {
    e.preventDefault();
    setError(undefined);
    setBusy(true);
    try {
      const q = new URLSearchParams({ statement_balance: balance.replace(/,/g, ''), ...(asOf && { as_of: asOf }) });
      setRecon((await api<{ data: Reconciliation }>(`/bank-accounts/${encodeURIComponent(code)}/reconciliation?${q}`)).data);
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }

  const columns: Column<Item>[] = [
    { key: 'date', header: t('common.date'), mobile: 'title', render: (i) => <BsDate ad={i.date} /> },
    { key: 'description', header: t('common.description') },
    { key: 'amount', header: t('common.amountRs'), money: true },
  ];

  return (
    <>
      <PageHeader title={t('nav.banking')} crumbs={[{ label: t('nav.ledger'), href: '/ledger' }]} subtitle={t('banking.help')} />
      {tags.error !== undefined && <ErrorState error={tags.error} onRetry={tags.reload} />}
      {tags.data && banks.length === 0 && <Alert severity="info" sx={{ mb: 2 }}>{t('banking.noBanks')}</Alert>}
      {error !== undefined && <ErrorState error={error} />}
      <Paper component="form" method="post" noValidate onSubmit={reconcile} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }} className="no-print">
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr 1.4fr' }, alignItems: 'start' }}>
          <TextField select required label={t('banking.account')} value={code} onChange={(e) => { setCode(e.target.value); setRecon(null); }}>
            {banks.map((b) => <MenuItem key={b} value={b}>{b}</MenuItem>)}
          </TextField>
          <TextField required label={t('banking.statementBalance')} value={balance} onChange={(e) => setBalance(e.target.value)} slotProps={{ htmlInput: { inputMode: 'decimal' } }} error={balance !== '' && !SIGNED.test(balance.replace(/,/g, ''))} helperText={t('banking.balanceHelp')} />
          <DateField label={t('common.asOf')} value={asOf} onChange={setAsOf} helperText={t('banking.asOfHelp')} />
        </Box>
        <Box sx={{ display: 'flex', gap: 1, mt: 2, flexWrap: 'wrap' }}>
          <Button variant="outlined" component="label" startIcon={<UploadFileOutlined />} disabled={!code || busy}>
            {t('banking.importCsv')}
            <input hidden type="file" accept=".csv,text/csv" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) upload(f); }} />
          </Button>
          <Button type="submit" variant="contained" disabled={!code || !SIGNED.test(balance.replace(/,/g, '')) || busy}>{t('banking.reconcile')}</Button>
        </Box>
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>{t('banking.csvFormat')}</Typography>
      </Paper>
      {recon && (
        <>
          <ReportHeader title={`${t('nav.banking')} · ${recon.account}`} asOf={recon.as_of} />
          <Paper sx={{ p: 2.5, mb: 3 }}>
            <Grid container spacing={2} sx={{ alignItems: 'center' }}>
              <Grid size={{ xs: 12, md: 3 }}><StatusChip kind="balance" value={recon.reconciled ? 'ok' : 'fail'} /></Grid>
              <Grid size={{ xs: 4, md: 3 }}><Typography variant="caption" color="text.secondary" component="div">{t('banking.ledgerBalance')}</Typography><MoneyText value={recon.ledger_balance} strong /></Grid>
              <Grid size={{ xs: 4, md: 3 }}><Typography variant="caption" color="text.secondary" component="div">{t('banking.adjusted')}</Typography><MoneyText value={recon.adjusted_ledger_balance} strong /></Grid>
              <Grid size={{ xs: 4, md: 3 }}><Typography variant="caption" color="text.secondary" component="div">{t('banking.statement')}</Typography><MoneyText value={recon.statement_balance} strong /></Grid>
            </Grid>
          </Paper>
          <Typography variant="h2" sx={{ mb: 1 }}>{t('banking.notOnStatement')}</Typography>
          <DataTable label={t('banking.notOnStatement')} columns={columns} rows={recon.unmatched_ledger} rowKey={(i) => `${i.date}-${i.description}-${i.amount}`} emptyTitle={t('common.none')} csvName={`unmatched-ledger-${recon.account}`} />
          <Typography variant="h2" sx={{ mb: 1 }}>{t('banking.notInLedger')}</Typography>
          <DataTable label={t('banking.notInLedger')} columns={columns} rows={recon.unmatched_statement} rowKey={(i) => `${i.date}-${i.description}-${i.amount}`} emptyTitle={t('common.none')} csvName={`unmatched-statement-${recon.account}`} />
        </>
      )}
    </>
  );
}
