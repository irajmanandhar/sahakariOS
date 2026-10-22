'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { type FormEvent, useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import { MoneyField, SubmitButton } from '@/components/fields';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, FormError } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { fieldError, useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import { blankLine, journalTotals, type JournalLineInput } from '@/lib/ledger';
import { parseMoneyInput } from '@/lib/money';
import { useSession } from '@/lib/session';
import type { JournalEntry, LedgerAccount } from '@/lib/types';

/**
 * Manual journal: lines must balance (debit = credit, checked exactly as you type) before posting.
 * The API re-checks everything; posting is final (corrections are reversals).
 */
export default function NewJournalPage() {
  const t = useT();
  const router = useRouter();
  const toast = useToast();
  const { businessDay } = useSession();
  const accounts = useApi<LedgerAccount[]>('/ledger-accounts');
  const postable = (accounts.data ?? []).filter((a) => a.is_postable && a.status === 'active');
  const [description, setDescription] = useState('');
  const [lines, setLines] = useState<JournalLineInput[]>([blankLine(), blankLine()]);
  const [review, setReview] = useState(false);
  const totals = journalTotals(lines);
  const dirty = description !== '' || lines.some((l) => l.account || l.debit || l.credit);
  useUnsavedChanges(dirty);

  const post = useSubmit('journal', async ({ idempotencyKey }) => {
    const body = {
      description,
      lines: lines.map((l) => ({
        account_code: l.account,
        ...(l.debit ? { debit: parseMoneyInput(l.debit) } : { credit: parseMoneyInput(l.credit) }),
        description: l.description || null,
      })),
    };
    return (await api<{ data: JournalEntry }>('/journal-entries', { method: 'POST', body, idempotencyKey })).data;
  });

  const set = (i: number, change: Partial<JournalLineInput>) => setLines((ls) => ls.map((l, n) => (n === i ? { ...l, ...change } : l)));

  async function confirmPost() {
    const entry = await post.attempt();
    toast.success(t('ledger.posted', { reference: entry.reference }));
    setLines([]); // nothing unsaved any more
    setDescription('');
    router.push(`/ledger/journal/${entry.id}`);
  }

  if (accounts.error !== undefined) return <ErrorState error={accounts.error} onRetry={accounts.reload} />;

  return (
    <Box component="form" method="post" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); if (totals.balanced) setReview(true); }}>
      <PageHeader title={t('nav.newJournal')} crumbs={[{ label: t('nav.ledger'), href: '/ledger' }, { label: t('nav.journal'), href: '/ledger/journal' }]} subtitle={t('ledger.newHelp')} />
      {!businessDay && <Alert severity="warning" sx={{ mb: 2 }}>{t('ledger.needsDay')}</Alert>}
      <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>{t('ledger.makerChecker')}</Alert>
      <FormError error={post.error} />
      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 2 }}>
        <TextField
          fullWidth
          required
          label={t('common.description')}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          slotProps={{ htmlInput: { maxLength: 500 } }}
          {...fieldError(post.fieldErrors, 'description')}
          sx={{ mb: 3 }}
        />
        <Typography variant="h2" sx={{ mb: 1.5 }}>{t('ledger.lines')}</Typography>
        <Box component="ol" sx={{ listStyle: 'none', p: 0, m: 0, display: 'grid', gap: 1.5 }}>
          {lines.map((l, i) => (
            <Box component="li" key={i} aria-label={t('ledger.lineN', { n: i + 1 })} sx={{ display: 'grid', gap: 1, gridTemplateColumns: { xs: '1fr 1fr', md: 'minmax(220px, 2.2fr) 1fr 1fr 1.6fr auto' }, alignItems: 'start', pb: 1.5, borderBottom: 1, borderColor: 'divider' }}>
              <Autocomplete
                options={postable}
                loading={accounts.loading}
                value={postable.find((a) => a.code === l.account) ?? null}
                onChange={(_, a) => set(i, { account: a?.code ?? '' })}
                getOptionLabel={(a) => `${a.code} · ${a.name}`}
                isOptionEqualToValue={(a, b) => a.id === b.id}
                sx={{ gridColumn: { xs: '1 / -1', md: 'auto' } }}
                renderInput={(p) => <TextField {...p} required label={t('ledger.account')} {...fieldError(post.fieldErrors, `lines.${i}.account_code`)} />}
              />
              <MoneyField label={t('ledger.debit')} value={l.debit} onChange={(v) => set(i, { debit: v, credit: v ? '' : l.credit })} {...fieldError(post.fieldErrors, `lines.${i}.debit`)} />
              <MoneyField label={t('ledger.credit')} value={l.credit} onChange={(v) => set(i, { credit: v, debit: v ? '' : l.debit })} {...fieldError(post.fieldErrors, `lines.${i}.credit`)} />
              <TextField label={t('ledger.lineDescription')} value={l.description} onChange={(e) => set(i, { description: e.target.value })} sx={{ gridColumn: { xs: '1 / -1', md: 'auto' } }} />
              <IconButton aria-label={t('ledger.removeLine', { n: i + 1 })} disabled={lines.length <= 2} onClick={() => setLines((ls) => ls.filter((_, n) => n !== i))} sx={{ justifySelf: 'start', mt: 0.25 }}>
                <DeleteOutlined />
              </IconButton>
            </Box>
          ))}
        </Box>
        <Button startIcon={<AddOutlined />} onClick={() => setLines((ls) => [...ls, blankLine()])} sx={{ mt: 1.5 }}>{t('ledger.addLine')}</Button>
      </Paper>

      <Paper role="status" aria-live="polite" sx={{ p: 2, mb: 2, display: 'flex', gap: 3, flexWrap: 'wrap', alignItems: 'center', position: 'sticky', bottom: 0, zIndex: 2 }}>
        <Typography variant="body2">{t('ledger.totalDebit')} <MoneyText value={totals.debit} strong /></Typography>
        <Typography variant="body2">{t('ledger.totalCredit')} <MoneyText value={totals.credit} strong /></Typography>
        <Typography variant="body2">{t('ledger.difference')} <MoneyText value={totals.difference} strong negativeColor /></Typography>
        <StatusChip kind="balance" value={totals.balanced ? 'ok' : 'fail'} />
        <Box sx={{ flexGrow: 1 }} />
        <SubmitButton disabled={!totals.balanced || !description.trim()} submitting={post.submitting}>{t('ledger.reviewPost')}</SubmitButton>
      </Paper>

      <ConfirmDialog open={review} title={t('ledger.postTitle')} confirmLabel={t('ledger.post')} onConfirm={confirmPost} onClose={() => setReview(false)}>
        {t('ledger.postBody', { lines: lines.length })} <MoneyText value={totals.debit} prefix strong />
      </ConfirmDialog>
    </Box>
  );
}
