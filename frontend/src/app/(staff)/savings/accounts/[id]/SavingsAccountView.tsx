'use client';

import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import PrintOutlined from '@mui/icons-material/PrintOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { useParams } from 'next/navigation';
import { type FormEvent, type ReactNode, useState } from 'react';
import BsDate from '@/components/BsDate';
import ConfirmDialog from '@/components/ConfirmDialog';
import DataTable, { type Column } from '@/components/DataTable';
import { MoneyField } from '@/components/fields';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import ReportHeader from '@/components/ReportHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import { compareMoney, fromPaisa, parseMoneyInput, toPaisa } from '@/lib/money';
import { LARGE_CASH_WITHDRAWAL_ABOVE, SOURCE_OF_FUNDS_FROM } from '@/lib/rules';
import { useSession } from '@/lib/session';
import type { Member, PassbookLine, SavingsPosting, SavingsStatement } from '@/lib/types';

type Kind = 'deposits' | 'withdrawals';

/** Cash deposit or withdrawal at the counter: amount, the AML prompts the amount calls for, review, post. */
function CounterPanel({ account, member, onPosted }: { account: SavingsStatement; member: Member | undefined; onPosted: () => void }) {
  const t = useT();
  const toast = useToast();
  const { can, businessDay } = useSession();
  const kinds = (['deposits', 'withdrawals'] as Kind[]).filter((k) => can(k === 'deposits' ? 'savings.deposit' : 'savings.withdraw'));
  const [kind, setKind] = useState<Kind>(kinds[0]);
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');
  const [narration, setNarration] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [receipt, setReceipt] = useState<SavingsPosting & { kind: Kind } | null>(null);
  const value = parseMoneyInput(amount);
  const deposit = kind === 'deposits';
  const needsSource = deposit && value !== null && compareMoney(value, SOURCE_OF_FUNDS_FROM) >= 0;
  const largeCash = !deposit && value !== null && compareMoney(value, LARGE_CASH_WITHDRAWAL_ABOVE) > 0;
  useUnsavedChanges(amount !== '');

  const post = useSubmit('sav', async ({ idempotencyKey }) => {
    const extra = deposit ? { source_of_funds: note || null } : { large_cash_reason: note || null };
    return (await api<{ data: SavingsPosting }>(`/savings-accounts/${account.id}/${kind}`, { method: 'POST', body: { amount: value, narration: narration || null, ...extra }, idempotencyKey })).data;
  });

  if (kinds.length === 0) return null;
  const ready = value !== null && compareMoney(value, '0.00') > 0 && (!needsSource || note.trim() !== '') && (!largeCash || note.trim() !== '');

  return (
    <Paper component="section" aria-label={t('savings.counter')} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }} className="no-print">
      <Typography variant="h2" sx={{ mb: 1 }}>{t('savings.counter')}</Typography>
      {!businessDay && <Alert severity="warning" sx={{ mb: 2 }}>{t('ledger.needsDay')}</Alert>}
      {account.status !== 'active' && <Alert severity="warning" sx={{ mb: 2 }}>{t('savings.notActive')}</Alert>}
      {kinds.length > 1 && (
        <Tabs value={kind} onChange={(_, k: Kind) => { setKind(k); setNote(''); }} sx={{ mb: 2 }} aria-label={t('savings.counter')}>
          <Tab value="deposits" label={t('savings.deposit')} />
          <Tab value="withdrawals" label={t('savings.withdraw')} />
        </Tabs>
      )}
      {receipt && (
        <Alert severity="success" sx={{ mb: 2 }} onClose={() => setReceipt(null)}>
          {t(receipt.kind === 'deposits' ? 'savings.depositPosted' : 'savings.withdrawalPosted')} <MoneyText value={receipt.amount} prefix strong /> · {t('savings.balanceAfter')} <MoneyText value={receipt.balance_after} prefix strong />
          {can('ledger.view') && <> · <Link component={NextLink} href={`/ledger/journal/${receipt.journal_entry_id}`}>{t('savings.viewJournal')}</Link></>}
        </Alert>
      )}
      <Box component="form" method="post" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); if (ready) setConfirm(true); }} sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 2fr' }, alignItems: 'start' }}>
        <MoneyField required label={t('common.amountRs')} value={amount} onChange={setAmount} />
        <TextField label={t('savings.narration')} value={narration} onChange={(e) => setNarration(e.target.value)} slotProps={{ htmlInput: { maxLength: 300 } }} helperText={t('savings.narrationHelp')} />
        {needsSource && (
          <TextField sx={{ gridColumn: '1 / -1' }} required multiline label={t('members.sourceOfFunds')} value={note} onChange={(e) => setNote(e.target.value)} helperText={t('savings.sourceHelp')} slotProps={{ htmlInput: { maxLength: 500 } }} />
        )}
        {largeCash && (
          <>
            <TextField sx={{ gridColumn: '1 / -1' }} required multiline label={t('savings.largeCashReason')} value={note} onChange={(e) => setNote(e.target.value)} helperText={t('savings.largeCashHelp')} slotProps={{ htmlInput: { maxLength: 500 } }} />
            {!can('savings.large_cash_withdrawal') && <Alert severity="warning" sx={{ gridColumn: '1 / -1' }}>{t('savings.largeCashPermission')}</Alert>}
          </>
        )}
        <Box sx={{ gridColumn: '1 / -1' }}>
          <Button type="submit" variant="contained" color={deposit ? 'primary' : 'secondary'} disabled={!ready || post.submitting}>{t(deposit ? 'savings.reviewDeposit' : 'savings.reviewWithdrawal')}</Button>
        </Box>
      </Box>
      <ConfirmDialog
        open={confirm}
        title={t(deposit ? 'savings.confirmDeposit' : 'savings.confirmWithdrawal')}
        confirmLabel={t(deposit ? 'savings.postDeposit' : 'savings.postWithdrawal')}
        onClose={() => setConfirm(false)}
        onConfirm={async () => {
          const result = await post.attempt();
          setReceipt({ ...result, kind });
          toast.success(t(deposit ? 'savings.depositPosted' : 'savings.withdrawalPosted'));
          setAmount('');
          setNote('');
          setNarration('');
          onPosted();
        }}
      >
        <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: 1, color: 'text.primary' }}>
          <dt>{t('aml.member')}</dt><dd style={{ margin: 0 }}>{member ? `${member.member_number ?? ''} ${member.full_name}` : account.member_id}</dd>
          <dt>{t('savings.accountNo')}</dt><dd style={{ margin: 0 }}>{account.account_number} · {account.product.name}</dd>
          <dt>{t('common.amount')}</dt><dd style={{ margin: 0 }}><MoneyText value={value} prefix strong /></dd>
          {note && <><dt>{deposit ? t('members.sourceOfFunds') : t('savings.largeCashReason')}</dt><dd style={{ margin: 0 }}>{note}</dd></>}
        </Box>
      </ConfirmDialog>
    </Paper>
  );
}

/** One savings account: balances, counter deposit/withdrawal, and the passbook (BS/AD, voucher, running balance). */
export default function SavingsAccountView() {
  const t = useT();
  const { can } = useSession();
  const { id } = useParams<{ id: string }>();
  const statement = useApi<SavingsStatement>(`/savings-accounts/${id}`);
  const a = statement.data;
  const member = useApi<Member>(a && can('member.view') ? `/members/${a.member_id}` : null);

  if (statement.error !== undefined && !a) return <ErrorState error={statement.error} onRetry={statement.reload} />;
  if (!a) return <LoadingBlock rows={8} />;

  const available = fromPaisa(toPaisa(a.balance) - toPaisa(a.hold_amount) - toPaisa(a.minimum_balance));
  const fact = (label: string, value: ReactNode) => (
    <Grid size={{ xs: 6, md: 3 }}>
      <Typography variant="caption" color="text.secondary" component="div">{label}</Typography>
      <Typography component="div" sx={{ fontWeight: 600 }}>{value}</Typography>
    </Grid>
  );

  const columns: Column<PassbookLine>[] = [
    { key: 'date', header: t('common.date'), mobile: 'title', render: (l) => <BsDate ad={l.date} bs={l.date_bs} /> },
    { key: 'reference', header: t('savings.voucher'), value: (l) => l.reference ?? '' },
    { key: 'particulars', header: t('savings.particulars') },
    { key: 'debit', header: t('ledger.debit'), money: true },
    { key: 'credit', header: t('ledger.credit'), money: true },
    { key: 'can_withdraw', header: t('savings.canWithdraw'), money: true },
    { key: 'balance', header: t('savings.balance'), money: true },
    { key: 'journal_entry_number', header: t('savings.journalNo'), hidden: true },
  ];

  return (
    <>
      <PageHeader
        title={<>{t('savings.accountTitle', { number: a.account_number })} <StatusChip kind="account" value={a.status} /></>}
        crumbs={[{ label: t('nav.savings'), href: '/savings' }, ...(member.data ? [{ label: member.data.full_name, href: `/members/${a.member_id}?tab=savings` }] : [])]}
        subtitle={`${a.product.code} · ${a.product.name}`}
        actions={<>
          <Button startIcon={<DownloadOutlined />} href={`/api/savings-accounts/${a.id}/passbook.csv`}>{t('common.downloadCsv')}</Button>
          <Button startIcon={<PrintOutlined />} onClick={() => window.print()}>{t('common.print')}</Button>
        </>}
      />
      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
        <Grid container spacing={2}>
          {fact(t('savings.balance'), <MoneyText value={a.balance} prefix />)}
          {fact(t('savings.hold'), <MoneyText value={a.hold_amount} prefix />)}
          {fact(t('savings.minimumBalance'), <MoneyText value={a.minimum_balance} prefix />)}
          {fact(t('savings.available'), <MoneyText value={available} prefix negativeColor />)}
          {fact(t('savings.accrued'), <MoneyText value={a.accrued_interest} prefix />)}
          {fact(t('savings.openedOn'), <BsDate ad={a.opened_on} />)}
          {a.maturity_date && fact(t('savings.maturity'), <BsDate ad={a.maturity_date} />)}
          {a.legacy_account_number && fact(t('savings.oldAccount'), a.legacy_account_number)}
        </Grid>
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1.5 }}>{t('savings.availableHelp')}</Typography>
      </Paper>
      {a.status === 'active' && <CounterPanel account={a} member={member.data} onPosted={statement.reload} />}
      <ReportHeader title={t('savings.passbook')} note={`${member.data ? `${member.data.member_number ?? ''} ${member.data.full_name} · ` : ''}${t('savings.accountTitle', { number: a.account_number })}`} />
      <DataTable label={t('savings.passbook')} columns={columns} rows={a.transactions} rowKey={(l) => l.id} emptyTitle={t('savings.noTransactions')} quickFilter />
      <Typography variant="caption" color="text.secondary">{t('savings.csvAudited')}</Typography>
    </>
  );
}
