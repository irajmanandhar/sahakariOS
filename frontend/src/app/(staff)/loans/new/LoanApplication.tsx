'use client';

import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter, useSearchParams } from 'next/navigation';
import { type FormEvent, useEffect, useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import { MoneyField } from '@/components/fields';
import MoneyText, { useMoney } from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import { ErrorState, FormError, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { searchMembers } from '@/lib/gaps';
import { fieldError, useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import { parseMoneyInput } from '@/lib/money';
import type { Loan, LoanProduct, Member, SavingsAccount } from '@/lib/types';

const empty = { product: '', principal: '', term: '12', grace: '0', purpose: '', pledged: '', valuation: '', collateral: '', cic: '' };

/** Guarantor picker: other active members, found with the member search (G5 stand-in). */
function Guarantors({ value, onChange, exclude, error }: { value: Member[]; onChange: (m: Member[]) => void; exclude: number; error?: string }) {
  const t = useT();
  const [text, setText] = useState('');
  const [options, setOptions] = useState<Member[]>([]);
  useEffect(() => {
    let live = true;
    if (text.trim()) searchMembers(text).then((m) => live && setOptions(m.filter((x) => x.status === 'active' && x.id !== exclude))).catch(() => {});
    return () => { live = false; };
  }, [text, exclude]);
  return (
    <Autocomplete
      multiple
      options={options}
      value={value}
      onChange={(_, v) => onChange(v)}
      onInputChange={(_, v) => setText(v)}
      filterOptions={(o) => o}
      getOptionLabel={(m) => `${m.member_number ?? '—'} ${m.full_name}`}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      renderInput={(p) => <TextField {...p} label={t('loans.guarantors')} error={!!error} helperText={error ?? t('loans.guarantorsHelp')} />}
    />
  );
}

/** New loan application for one member. Eligibility and limits (Act s.50, s.52; NCRA Std 3) are checked by the API. */
export default function LoanApplication() {
  const t = useT();
  const money = useMoney();
  const router = useRouter();
  const toast = useToast();
  const memberId = useSearchParams().get('member');
  const member = useApi<Member>(memberId ? `/members/${memberId}` : null);
  const products = useApi<LoanProduct[]>('/loan-products');
  const savings = useApi<SavingsAccount[]>(memberId ? `/members/${memberId}/savings-accounts` : null);
  const [form, setForm] = useState(empty);
  const [guarantors, setGuarantors] = useState<Member[]>([]);
  const [confirm, setConfirm] = useState(false);
  const product = products.data?.find((p) => String(p.id) === form.product);
  useUnsavedChanges(JSON.stringify(form) !== JSON.stringify(empty));

  const apply = useSubmit('loan', async () => (await api<{ data: Loan }>(`/members/${memberId}/loans`, { method: 'POST', body: {
    loan_product_id: Number(form.product),
    principal: parseMoneyInput(form.principal) ?? form.principal,
    term_months: Number(form.term),
    grace_months: Number(form.grace || 0),
    purpose: form.purpose,
    pledged_savings_account_id: product?.collateral_type === 'savings' && form.pledged ? Number(form.pledged) : null,
    collateral_value: product?.collateral_type === 'real_estate' ? parseMoneyInput(form.valuation) ?? (form.valuation || null) : null,
    collateral_description: product?.collateral_type === 'real_estate' ? form.collateral || null : null,
    guarantor_member_ids: product?.collateral_type === 'unsecured' ? guarantors.map((g) => g.id) : [],
    cic_report_reference: form.cic || null,
  } })).data);
  const err = (k: string) => fieldError(apply.fieldErrors, k);

  if (!memberId) return <Alert severity="info">{t('loans.pickMember')}</Alert>;
  if (member.error !== undefined) return <ErrorState error={member.error} onRetry={member.reload} />;
  if (!member.data || !products.data) return <LoadingBlock rows={6} />;

  const set = (k: keyof typeof empty) => (v: string) => setForm({ ...form, [k]: v });

  return (
    <Box component="form" method="post" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); setConfirm(true); }}>
      <PageHeader title={t('loans.newApplication')} crumbs={[{ label: t('nav.loans'), href: '/loans' }, { label: member.data.full_name, href: `/members/${memberId}?tab=loans` }]} subtitle={t('loans.applyHelp')} />
      {member.data.status !== 'active' && <Alert severity="warning" sx={{ mb: 2 }}>{t('loans.memberNotActive')}</Alert>}
      <FormError error={apply.error} />
      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 2 }}>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
          <TextField select required label={t('loans.product')} value={form.product} onChange={(e) => set('product')(e.target.value)} {...err('loan_product_id')} sx={{ gridColumn: '1 / -1' }}>
            {products.data.filter((p) => p.status === 'active').map((p) => <MenuItem key={p.id} value={String(p.id)}>{p.code} · {p.name} · {t(`loans.collateralType.${p.collateral_type}`)} · {p.interest_rate}%</MenuItem>)}
          </TextField>
          {product && (
            <Alert severity="info" variant="outlined" sx={{ gridColumn: '1 / -1' }}>
              {t('loans.productLimits', { amount: money(product.max_amount), term: product.max_term_months, grace: product.max_grace_months, method: t(`loans.method.${product.schedule_method}`), frequency: t(`loans.frequency.${product.repayment_frequency}`) })}
            </Alert>
          )}
          <MoneyField required label={t('loans.principalRequested')} value={form.principal} onChange={set('principal')} {...err('principal')} />
          <TextField required label={t('loans.purpose')} value={form.purpose} onChange={(e) => set('purpose')(e.target.value)} {...err('purpose')} slotProps={{ htmlInput: { maxLength: 300 } }} />
          <TextField required label={t('loans.termMonths')} value={form.term} onChange={(e) => set('term')(e.target.value.replace(/\D/g, ''))} {...err('term_months')} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
          <TextField label={t('loans.graceMonths')} value={form.grace} onChange={(e) => set('grace')(e.target.value.replace(/\D/g, ''))} {...err('grace_months')} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
          {product?.collateral_type === 'savings' && (
            <TextField select required label={t('loans.pledgedAccount')} value={form.pledged} onChange={(e) => set('pledged')(e.target.value)} {...err('pledged_savings_account_id')} sx={{ gridColumn: '1 / -1' }}>
              {(savings.data ?? []).filter((s) => s.status === 'active').map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.account_number} · {s.product.name} · {t('common.rs')} {money(s.balance)}</MenuItem>)}
            </TextField>
          )}
          {product?.collateral_type === 'real_estate' && <>
            <MoneyField required label={t('loans.valuation')} value={form.valuation} onChange={set('valuation')} {...err('collateral_value')} />
            <TextField required label={t('loans.collateralDescription')} value={form.collateral} onChange={(e) => set('collateral')(e.target.value)} {...err('collateral_description')} />
          </>}
          {product?.collateral_type === 'unsecured' && (
            <Box sx={{ gridColumn: '1 / -1' }}><Guarantors value={guarantors} onChange={setGuarantors} exclude={member.data.id} error={err('guarantor_member_ids').helperText} /></Box>
          )}
          <TextField label={t('loans.cic')} value={form.cic} onChange={(e) => set('cic')(e.target.value)} {...err('cic_report_reference')} sx={{ gridColumn: '1 / -1' }} />
        </Box>
      </Paper>
      <Box sx={{ display: 'flex', gap: 1, justifyContent: 'space-between' }}>
        <Button onClick={() => router.back()}>{t('common.cancel')}</Button>
        <Button type="submit" variant="contained" disabled={!product || !parseMoneyInput(form.principal) || !form.purpose.trim() || apply.submitting}>{t('loans.reviewApplication')}</Button>
      </Box>
      <ConfirmDialog open={confirm} title={t('loans.confirmApplication')} confirmLabel={t('loans.submitApplication')} onClose={() => setConfirm(false)}
        onConfirm={async () => {
          const loan = await apply.attempt();
          setForm(empty);
          toast.success(t('loans.applicationRecorded', { n: loan.loan_number }));
          router.push(`/loans/${loan.id}`);
        }}>
        <Typography component="span">{t('loans.applicationBody', { name: member.data.full_name, product: product ? `${product.code} ${product.name}` : '' })} <MoneyText value={parseMoneyInput(form.principal)} prefix strong /></Typography>
      </ConfirmDialog>
    </Box>
  );
}
