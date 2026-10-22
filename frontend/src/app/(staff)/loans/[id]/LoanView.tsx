'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import Link from '@mui/material/Link';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { useParams } from 'next/navigation';
import { type FormEvent, type ReactNode, useState } from 'react';
import BsDate from '@/components/BsDate';
import ConfirmDialog from '@/components/ConfirmDialog';
import DataTable, { type Column } from '@/components/DataTable';
import { MoneyField } from '@/components/fields';
import MoneyText, { useDigits, useMoney } from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import { compareMoney, parseMoneyInput } from '@/lib/money';
import { SOURCE_OF_FUNDS_FROM } from '@/lib/rules';
import { useSession } from '@/lib/session';
import type { LoanDetail, LoanProduct, RepaymentQuote, RepaymentResult, SavingsAccount } from '@/lib/types';

type Schedule = LoanDetail['schedule'][number];
type Txn = LoanDetail['transactions'][number];

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Paper component="section" aria-label={title} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
      <Typography variant="h2" sx={{ mb: 2 }}>{title}</Typography>
      {children}
    </Paper>
  );
}

function Repay({ loan, quote, savings, onPaid }: { loan: LoanDetail; quote: RepaymentQuote; savings: SavingsAccount[]; onPaid: () => void }) {
  const t = useT();
  const money = useMoney();
  const toast = useToast();
  const [amount, setAmount] = useState('');
  const [channel, setChannel] = useState<'cash' | 'savings'>('cash');
  const [from, setFrom] = useState('');
  const [source, setSource] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [receipt, setReceipt] = useState<RepaymentResult | null>(null);
  const value = parseMoneyInput(amount);
  const needsSource = channel === 'cash' && value !== null && compareMoney(value, SOURCE_OF_FUNDS_FROM) >= 0;
  const ready = value !== null && compareMoney(value, '0.00') > 0 && (channel === 'cash' || from !== '') && (!needsSource || source.trim() !== '');
  useUnsavedChanges(amount !== '');
  const repay = useSubmit('repay', async ({ idempotencyKey }) => (await api<{ data: RepaymentResult }>(`/loans/${loan.id}/repayments`, {
    method: 'POST', idempotencyKey,
    body: { amount: value, channel, savings_account_id: channel === 'savings' ? Number(from) : null, source_of_funds: needsSource ? source : null },
  })).data);

  return (
    <>
      {receipt && (
        <Alert severity="success" sx={{ mb: 2 }} onClose={() => setReceipt(null)}>
          {t('loans.repaid')} <MoneyText value={receipt.amount} prefix strong />: {t('loans.penalty')} <MoneyText value={receipt.penalty} />, {t('loans.interest')} <MoneyText value={receipt.interest} />, {t('loans.principal')} <MoneyText value={receipt.principal} />. {t('loans.outstandingAfter')} <MoneyText value={receipt.outstanding_after} prefix strong />
        </Alert>
      )}
      <Box component="form" method="post" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); if (ready) setConfirm(true); }} sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, alignItems: 'start' }}>
        <Box>
          <MoneyField fullWidth required label={t('common.amountRs')} value={amount} onChange={setAmount} helperText={t('loans.minimum', { amount: money(quote.minimum_payment) })} />
          <Box sx={{ display: 'flex', gap: 1, mt: 0.5 }}>
            <Button size="small" onClick={() => setAmount(quote.minimum_payment)}>{t('loans.payMinimum')}</Button>
            <Button size="small" onClick={() => setAmount(quote.payoff)}>{t('loans.payOff')}</Button>
          </Box>
        </Box>
        <Box>
          <ToggleButtonGroup exclusive size="small" value={channel} onChange={(_, v) => v && setChannel(v)} aria-label={t('loans.channel')} sx={{ mb: 1.5 }}>
            <ToggleButton value="cash">{t('loans.cash')}</ToggleButton>
            <ToggleButton value="savings">{t('loans.fromSavings')}</ToggleButton>
          </ToggleButtonGroup>
          {channel === 'savings' && (
            <TextField select fullWidth required label={t('loans.savingsAccount')} value={from} onChange={(e) => setFrom(e.target.value)} helperText={t('loans.ownAccountOnly')}>
              {savings.filter((s) => s.status === 'active').map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.account_number} · {s.product.name} · {t('common.rs')} {money(s.balance)}</MenuItem>)}
            </TextField>
          )}
        </Box>
        {needsSource && <TextField sx={{ gridColumn: '1 / -1' }} required multiline label={t('members.sourceOfFunds')} value={source} onChange={(e) => setSource(e.target.value)} helperText={t('savings.sourceHelp')} />}
        <Box sx={{ gridColumn: '1 / -1' }}>
          <Button type="submit" variant="contained" disabled={!ready || repay.submitting}>{t('loans.reviewRepayment')}</Button>
        </Box>
      </Box>
      <ConfirmDialog open={confirm} title={t('loans.confirmRepayment', { n: loan.loan_number })} confirmLabel={t('loans.postRepayment')} onClose={() => setConfirm(false)}
        onConfirm={async () => {
          setReceipt(await repay.attempt());
          toast.success(t('loans.repaid'));
          setAmount('');
          setSource('');
          onPaid();
        }}>
        {t('loans.repaymentBody', { channel: channel === 'cash' ? t('loans.cash') : t('loans.fromSavings') })} <MoneyText value={value} prefix strong />. {t('loans.allocationOrder')}
      </ConfirmDialog>
    </>
  );
}

/**
 * One loan through its life: decision (by someone other than the applicant's entrant), disbursement
 * into the borrower's own savings, schedule, repayment quote and repayment. Rules are the API's.
 */
export default function LoanView() {
  const t = useT();
  const toast = useToast();
  const digits = useDigits();
  const { can } = useSession();
  const { id } = useParams<{ id: string }>();
  const loan = useApi<LoanDetail>(`/loans/${id}`);
  const l = loan.data;
  const quote = useApi<RepaymentQuote>(l?.status === 'disbursed' ? `/loans/${id}/repayment-quote` : null);
  const needSavings = !!l && ((l.status === 'approved' && can('loan.disburse')) || (l.status === 'disbursed' && can('loan.repay')));
  const savings = useApi<SavingsAccount[]>(needSavings && can('savings.view') ? `/members/${l!.member.id}/savings-accounts` : null);
  const products = useApi<LoanProduct[]>(l ? '/loan-products' : null);
  const product = products.data?.find((p) => p.id === l?.product.id);
  const [action, setAction] = useState<'approve' | 'reject' | 'disburse' | null>(null);
  const [into, setInto] = useState('');
  const disburse = useSubmit('disburse', async ({ idempotencyKey }) => api(`/loans/${id}/disburse`, { method: 'POST', body: { savings_account_id: Number(into) }, idempotencyKey }));

  if (loan.error !== undefined && !l) return <ErrorState error={loan.error} onRetry={loan.reload} />;
  if (!l) return <LoadingBlock rows={8} />;
  const refresh = () => { loan.reload(); quote.reload(); savings.reload(); };

  const fact = (label: string, value: ReactNode) => (
    <Grid size={{ xs: 6, md: 3 }}>
      <Typography variant="caption" color="text.secondary" component="div">{label}</Typography>
      <Typography component="div">{value ?? '—'}</Typography>
    </Grid>
  );

  const scheduleColumns: Column<Schedule>[] = [
    { key: 'installment_number', header: '#', width: 56 },
    { key: 'due_date', header: t('loans.due'), mobile: 'title', render: (s) => <BsDate ad={s.due_date} /> },
    { key: 'principal_due', header: t('loans.principalDue'), money: true },
    { key: 'interest_projected', header: t('loans.interestEstimate'), money: true },
    { key: 'principal_paid', header: t('loans.principalPaid'), money: true },
  ];
  const txnColumns: Column<Txn>[] = [
    { key: 'date', header: t('common.date'), mobile: 'title', render: (x) => <BsDate ad={x.date} /> },
    { key: 'type', header: t('common.type'), value: (x) => (x.type === 'disbursement' ? t('loans.disbursement') : x.type === 'repayment' ? t('loans.repayment') : x.type) },
    { key: 'amount', header: t('common.amount'), money: true },
    { key: 'principal', header: t('loans.principal'), money: true },
    { key: 'interest', header: t('loans.interest'), money: true },
    { key: 'penalty', header: t('loans.penalty'), money: true },
    { key: 'fee', header: t('loans.fee'), money: true, hidden: true },
    { key: 'outstanding_after', header: t('loans.outstandingAfter'), money: true },
  ];

  return (
    <>
      <PageHeader
        title={<>{t('loans.title', { n: digits(l.loan_number) })} <StatusChip kind="loan" value={l.status} />{l.status === 'disbursed' && <StatusChip kind="classification" value={l.classification} />}</>}
        crumbs={[{ label: t('nav.loans'), href: '/loans' }]}
        subtitle={<><Link component={NextLink} href={`/members/${l.member.id}?tab=loans`}>{l.member.member_number ? `${digits(l.member.member_number)} · ` : ''}{l.member.full_name}</Link> · {l.product.code} {l.product.name}</>}
        actions={<>
          {l.status === 'applied' && can('loan.decide') && <>
            <Button variant="contained" color="success" onClick={() => setAction('approve')}>{t('members.approve')}</Button>
            <Button variant="outlined" color="error" onClick={() => setAction('reject')}>{t('members.reject')}</Button>
          </>}
        </>}
      />
      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
        <Grid container spacing={2}>
          {fact(t('loans.principal'), <MoneyText value={l.principal} prefix />)}
          {fact(t('loans.outstanding'), <MoneyText value={l.outstanding_principal} prefix />)}
          {fact(t('loans.rate'), `${l.interest_rate}%`)}
          {fact(t('loans.term'), t('loans.termValue', { term: digits(l.term_months), grace: digits(l.grace_months) }))}
          {fact(t('loans.purpose'), l.purpose)}
          {fact(t('members.appliedOn'), <BsDate ad={l.applied_on} />)}
          {fact(t('loans.disbursedOn'), <BsDate ad={l.disbursed_on} />)}
          {fact(t('loans.interestPaidThrough'), <BsDate ad={l.interest_paid_through} />)}
          {l.decision_reason && fact(t('members.decisionReason'), l.decision_reason)}
          {product && fact(t('loans.collateral'), t(`loans.collateralType.${product.collateral_type}`))}
        </Grid>
      </Paper>

      {l.status === 'approved' && can('loan.disburse') && (
        <Panel title={t('loans.disburse')}>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('loans.disburseHelp', { fee: product?.service_fee_percent ?? '…' })}</Typography>
          {savings.error !== undefined && <ErrorState error={savings.error} onRetry={savings.reload} />}
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
            <TextField select label={t('loans.intoAccount')} value={into} onChange={(e) => setInto(e.target.value)} sx={{ minWidth: 300 }}>
              {(savings.data ?? []).filter((s) => s.status === 'active').map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.account_number} · {s.product.name}</MenuItem>)}
            </TextField>
            <Button variant="contained" disabled={!into} onClick={() => setAction('disburse')}>{t('loans.reviewDisbursement')}</Button>
          </Box>
        </Panel>
      )}

      {l.status === 'disbursed' && (
        <Panel title={t('loans.dueToday')}>
          {quote.error !== undefined ? <ErrorState error={quote.error} onRetry={quote.reload} /> : !quote.data ? <LoadingBlock rows={2} /> : (
            <>
              <Grid container spacing={2} sx={{ mb: can('loan.repay') ? 3 : 0 }}>
                {fact(t('loans.quoteDate'), <BsDate ad={quote.data.date} />)}
                {fact(t('loans.penalty'), <MoneyText value={quote.data.penalty} />)}
                {fact(t('loans.interest'), <MoneyText value={quote.data.interest} />)}
                {fact(t('loans.principalOverdue'), <MoneyText value={quote.data.principal_overdue} />)}
                {fact(t('loans.minimumPayment'), <MoneyText value={quote.data.minimum_payment} strong />)}
                {fact(t('loans.payoff'), <MoneyText value={quote.data.payoff} strong />)}
              </Grid>
              {can('loan.repay') && <Repay loan={l} quote={quote.data} savings={savings.data ?? []} onPaid={refresh} />}
            </>
          )}
        </Panel>
      )}

      <Typography variant="h2" sx={{ mb: 1 }}>{t('loans.schedule')}</Typography>
      <DataTable label={t('loans.schedule')} columns={scheduleColumns} rows={l.schedule} rowKey={(s) => s.installment_number} emptyTitle={t('loans.noSchedule')} csvName={`loan-${l.loan_number}-schedule`} />
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: -2, mb: 3 }}>{t('loans.scheduleHelp')}</Typography>
      <Typography variant="h2" sx={{ mb: 1 }}>{t('loans.transactions')}</Typography>
      <DataTable label={t('loans.transactions')} columns={txnColumns} rows={l.transactions} rowKey={(x) => x.id} emptyTitle={t('loans.noTransactions')} csvName={`loan-${l.loan_number}-transactions`} />

      <ConfirmDialog open={action === 'approve'} title={t('loans.approveTitle', { n: l.loan_number })} confirmLabel={t('members.approve')} onClose={() => setAction(null)}
        onConfirm={async () => { await api(`/loans/${id}/approve`, { method: 'POST' }); toast.success(t('loans.approved')); refresh(); }}>
        {t('loans.approveBody')}
      </ConfirmDialog>
      <ConfirmDialog open={action === 'reject'} title={t('loans.rejectTitle', { n: l.loan_number })} confirmLabel={t('members.reject')} danger reasonLabel={t('members.rejectReason')} onClose={() => setAction(null)}
        onConfirm={async (reason) => { await api(`/loans/${id}/reject`, { method: 'POST', body: { reason } }); toast.success(t('loans.rejected')); refresh(); }}>
        {t('loans.rejectBody')}
      </ConfirmDialog>
      <ConfirmDialog open={action === 'disburse'} title={t('loans.disburseTitle', { n: l.loan_number })} confirmLabel={t('loans.disburse')} onClose={() => setAction(null)}
        onConfirm={async () => { await disburse.attempt(); toast.success(t('loans.disbursed')); refresh(); }}>
        {t('loans.disburseBody')} <MoneyText value={l.principal} prefix strong />
      </ConfirmDialog>
    </>
  );
}
