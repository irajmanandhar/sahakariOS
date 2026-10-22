'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Collapse from '@mui/material/Collapse';
import FormControlLabel from '@mui/material/FormControlLabel';
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
import type { CollateralType, LedgerAccount, LoanProduct } from '@/lib/types';

const empty = {
  code: '', name: '', collateral_type: 'savings' as CollateralType, interest_rate: '', penalty_percent: '0', service_fee_percent: '0',
  repayment_frequency: 'monthly', schedule_method: 'equal_principal', max_term_months: '12', max_grace_months: '0', max_amount: '', max_ltv_percent: '',
  is_productive_sector: false, ledger_account_code: '',
};

/** Loan products. Rates, fees and limits are the cooperative's decisions within the Act and NCRA standards. */
export default function LoanProductsPage() {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const products = useApi<LoanProduct[]>('/loan-products');
  const accounts = useApi<LedgerAccount[]>(can('loan.product_manage') && can('ledger.view') ? '/ledger-accounts' : null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(empty);
  useUnsavedChanges(open && JSON.stringify(form) !== JSON.stringify(empty));
  const create = useSubmit('loan-product', async () => api('/loan-products', { method: 'POST', body: {
    ...form,
    max_amount: parseMoneyInput(form.max_amount) ?? form.max_amount,
    max_term_months: Number(form.max_term_months),
    max_grace_months: Number(form.max_grace_months || 0),
    max_ltv_percent: form.collateral_type === 'real_estate' ? form.max_ltv_percent : null,
  } }));
  const err = (k: string) => fieldError(create.fieldErrors, k);
  const text = (k: keyof typeof empty, label: string, props: object = {}) => (
    <TextField label={label} value={form[k] as string} onChange={(e) => setForm({ ...form, [k]: e.target.value })} {...err(k)} {...props} />
  );

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (await create.submit() !== undefined) {
      toast.success(t('loans.productCreated', { code: form.code }));
      setForm(empty);
      setOpen(false);
      products.reload();
    }
  }

  const columns: Column<LoanProduct>[] = [
    { key: 'code', header: t('common.code'), mobile: 'title' },
    { key: 'name', header: t('common.name') },
    { key: 'collateral_type', header: t('loans.collateral'), value: (p) => `${t(`loans.collateralType.${p.collateral_type}`)}${p.max_ltv_percent ? ` (LTV ${p.max_ltv_percent}%)` : ''}` },
    { key: 'interest_rate', header: t('loans.rate'), value: (p) => `${p.interest_rate}%`, align: 'right' },
    { key: 'penalty_percent', header: t('loans.penaltyPercent'), value: (p) => `${p.penalty_percent}%`, align: 'right' },
    { key: 'service_fee_percent', header: t('loans.feePercent'), value: (p) => `${p.service_fee_percent}%`, align: 'right' },
    { key: 'repayment', header: t('loans.repaymentTerms'), value: (p) => `${t(`loans.frequency.${p.repayment_frequency}`)}, ${t(`loans.method.${p.schedule_method}`)}` },
    { key: 'max_amount', header: t('loans.maxAmount'), money: true },
    { key: 'max_term_months', header: t('loans.maxTerm'), align: 'right' },
    { key: 'status', header: t('common.status'), render: (p) => <StatusChip kind="account" value={p.status} /> },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.loanProducts')}
        crumbs={[{ label: t('nav.loans'), href: '/loans' }]}
        subtitle={t('loans.productsHelp')}
        actions={can('loan.product_manage') && !open && <Button variant="contained" startIcon={<AddOutlined />} onClick={() => setOpen(true)}>{t('loans.newProduct')}</Button>}
      />
      <Collapse in={open} unmountOnExit>
        <Paper component="form" method="post" noValidate onSubmit={onSubmit} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
          <Typography variant="h2" sx={{ mb: 1 }}>{t('loans.newProduct')}</Typography>
          <Alert severity="info" sx={{ mb: 2 }}>{t('loans.productRules')}</Alert>
          <FormError error={create.error} />
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr', md: 'repeat(4, 1fr)' } }}>
            {text('code', t('common.code'), { required: true })}
            {text('name', t('common.name'), { required: true, sx: { gridColumn: { md: 'span 3' } } })}
            <TextField select required label={t('loans.collateral')} value={form.collateral_type} onChange={(e) => setForm({ ...form, collateral_type: e.target.value as CollateralType })} {...err('collateral_type')}>
              {(['savings', 'real_estate', 'unsecured'] as const).map((c) => <MenuItem key={c} value={c}>{t(`loans.collateralType.${c}`)}</MenuItem>)}
            </TextField>
            {text('interest_rate', t('savings.ratePercent'), { required: true })}
            {text('penalty_percent', t('loans.penaltyPercent'), { helperText: err('penalty_percent').helperText ?? t('loans.penaltyMax') })}
            {text('service_fee_percent', t('loans.feePercent'))}
            <TextField select label={t('loans.repaymentFrequency')} value={form.repayment_frequency} onChange={(e) => setForm({ ...form, repayment_frequency: e.target.value })}>
              <MenuItem value="monthly">{t('loans.frequency.monthly')}</MenuItem>
              <MenuItem value="quarterly">{t('loans.frequency.quarterly')}</MenuItem>
            </TextField>
            <TextField select label={t('loans.scheduleMethod')} value={form.schedule_method} onChange={(e) => setForm({ ...form, schedule_method: e.target.value })}>
              <MenuItem value="equal_principal">{t('loans.method.equal_principal')}</MenuItem>
              <MenuItem value="equal_installment">{t('loans.method.equal_installment')}</MenuItem>
            </TextField>
            {text('max_term_months', t('loans.maxTerm'), { required: true })}
            {text('max_grace_months', t('loans.maxGrace'))}
            <MoneyField required label={t('loans.maxAmount')} value={form.max_amount} onChange={(v) => setForm({ ...form, max_amount: v })} {...err('max_amount')} />
            {form.collateral_type === 'real_estate' && text('max_ltv_percent', t('loans.maxLtv'), { required: true })}
            <TextField select={!!accounts.data} required label={t('loans.assetAccount')} value={form.ledger_account_code} onChange={(e) => setForm({ ...form, ledger_account_code: e.target.value })} {...err('ledger_account_code')} sx={{ gridColumn: { md: 'span 2' } }}>
              {accounts.data?.filter((a) => a.is_postable && a.type === 'asset').map((a) => <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>)}
            </TextField>
          </Box>
          <FormControlLabel sx={{ mt: 1 }} control={<Checkbox checked={form.is_productive_sector} onChange={(e) => setForm({ ...form, is_productive_sector: e.target.checked })} />} label={t('loans.productive')} />
          <Box sx={{ display: 'flex', gap: 1, mt: 2 }}>
            <SubmitButton submitting={create.submitting}>{t('common.create')}</SubmitButton>
            <Button onClick={() => { setOpen(false); setForm(empty); }}>{t('common.cancel')}</Button>
          </Box>
        </Paper>
      </Collapse>
      <DataTable label={t('nav.loanProducts')} columns={columns} rows={products.data ?? []} rowKey={(p) => p.id} loading={products.loading} error={products.error} onRetry={products.reload} emptyTitle={t('loans.noProducts')} csvName="loan-products" />
    </>
  );
}
