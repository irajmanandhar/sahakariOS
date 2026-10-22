'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Collapse from '@mui/material/Collapse';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, useState } from 'react';
import DataTable, { type Column } from '@/components/DataTable';
import { MoneyField, SubmitButton } from '@/components/fields';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { FormError } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { fieldError, useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import { parseMoneyInput } from '@/lib/money';
import { useSession } from '@/lib/session';
import type { LedgerAccount, SavingsProduct } from '@/lib/types';

const POSTINGS = ['monthly', 'quarterly', 'half_yearly', 'yearly', 'at_maturity'] as const;
const empty = { code: '', name: '', type: 'general' as SavingsProduct['type'], interest_rate: '', interest_posting: 'quarterly', minimum_balance: '0', term_months: '', ledger_account_code: '' };

/** Savings products. Rates and terms are decisions of the board (NCRA Std 5/2.1); nothing is pre-filled. */
export default function SavingsProductsPage() {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const products = useApi<SavingsProduct[]>('/savings-products');
  const accounts = useApi<LedgerAccount[]>(can('savings.product_manage') && can('ledger.view') ? '/ledger-accounts' : null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(empty);
  useUnsavedChanges(open && JSON.stringify(form) !== JSON.stringify(empty));
  const create = useSubmit('savings-product', async () => api('/savings-products', { method: 'POST', body: {
    ...form, minimum_balance: parseMoneyInput(form.minimum_balance) ?? form.minimum_balance, term_months: form.type === 'term' ? Number(form.term_months) : null,
  } }));
  const err = (k: string) => fieldError(create.fieldErrors, k);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (await create.submit() !== undefined) {
      toast.success(t('savings.productCreated', { code: form.code }));
      setForm(empty);
      setOpen(false);
      products.reload();
    }
  }

  const columns: Column<SavingsProduct>[] = [
    { key: 'code', header: t('common.code'), mobile: 'title' },
    { key: 'name', header: t('common.name') },
    { key: 'type', header: t('common.type'), value: (p) => t(`savings.types.${p.type}`) },
    { key: 'interest_rate', header: t('savings.rate'), value: (p) => `${p.interest_rate}%`, align: 'right' },
    { key: 'interest_posting', header: t('savings.posting'), value: (p) => t(`savings.postings.${p.interest_posting as 'monthly'}`) },
    { key: 'minimum_balance', header: t('savings.minimumBalance'), money: true },
    { key: 'term_months', header: t('savings.term'), value: (p) => (p.term_months ? t('savings.months', { n: p.term_months }) : '—') },
    { key: 'status', header: t('common.status'), render: (p) => <StatusChip kind="account" value={p.status} /> },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.savingsProducts')}
        crumbs={[{ label: t('nav.savings'), href: '/savings' }]}
        subtitle={t('savings.productsHelp')}
        actions={can('savings.product_manage') && !open && <Button variant="contained" startIcon={<AddOutlined />} onClick={() => setOpen(true)}>{t('savings.newProduct')}</Button>}
      />
      <Collapse in={open} unmountOnExit>
        <Paper component="form" method="post" noValidate onSubmit={onSubmit} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
          <Typography variant="h2" sx={{ mb: 1 }}>{t('savings.newProduct')}</Typography>
          <Alert severity="info" sx={{ mb: 2 }}>{t('savings.boardRates')}</Alert>
          <FormError error={create.error} />
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', md: 'repeat(4, 1fr)' } }}>
            <TextField required label={t('common.code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} {...err('code')} />
            <TextField required label={t('common.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} {...err('name')} sx={{ gridColumn: { md: 'span 3' } }} />
            <TextField select required label={t('common.type')} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as SavingsProduct['type'] })} {...err('type')}>
              {(['general', 'regular', 'term'] as const).map((ty) => <MenuItem key={ty} value={ty}>{t(`savings.types.${ty}`)}</MenuItem>)}
            </TextField>
            <TextField required label={t('savings.ratePercent')} value={form.interest_rate} onChange={(e) => setForm({ ...form, interest_rate: e.target.value })} slotProps={{ htmlInput: { inputMode: 'decimal' } }} {...err('interest_rate')} />
            <TextField select required label={t('savings.posting')} value={form.interest_posting} onChange={(e) => setForm({ ...form, interest_posting: e.target.value })} {...err('interest_posting')}>
              {POSTINGS.map((p) => <MenuItem key={p} value={p}>{t(`savings.postings.${p}`)}</MenuItem>)}
            </TextField>
            <MoneyField label={t('savings.minimumBalance')} value={form.minimum_balance} onChange={(v) => setForm({ ...form, minimum_balance: v })} {...err('minimum_balance')} />
            {form.type === 'term' && <TextField required label={t('savings.termMonths')} value={form.term_months} onChange={(e) => setForm({ ...form, term_months: e.target.value.replace(/\D/g, '') })} {...err('term_months')} helperText={err('term_months').helperText ?? t('savings.termMax')} />}
            <TextField select={!!accounts.data} required label={t('savings.liabilityAccount')} value={form.ledger_account_code} onChange={(e) => setForm({ ...form, ledger_account_code: e.target.value })} {...err('ledger_account_code')} sx={{ gridColumn: { md: 'span 2' } }}>
              {accounts.data?.filter((a) => a.is_postable && a.type === 'liability').map((a) => <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>)}
            </TextField>
          </Box>
          <Box sx={{ display: 'flex', gap: 1, mt: 2 }}>
            <SubmitButton submitting={create.submitting}>{t('common.create')}</SubmitButton>
            <Button onClick={() => { setOpen(false); setForm(empty); }}>{t('common.cancel')}</Button>
          </Box>
        </Paper>
      </Collapse>
      <DataTable label={t('nav.savingsProducts')} columns={columns} rows={products.data ?? []} rowKey={(p) => p.id} loading={products.loading} error={products.error} onRetry={products.reload} emptyTitle={t('savings.noProducts')} csvName="savings-products" />
    </>
  );
}
