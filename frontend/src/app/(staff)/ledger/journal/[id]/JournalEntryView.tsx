'use client';

import ArrowForward from '@mui/icons-material/ArrowForward';
import ArrowDownward from '@mui/icons-material/ArrowDownward';
import PrintOutlined from '@mui/icons-material/PrintOutlined';
import UndoOutlined from '@mui/icons-material/UndoOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import TableCell from '@mui/material/TableCell';
import TableFooter from '@mui/material/TableFooter';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { useState } from 'react';
import BsDate from '@/components/BsDate';
import ConfirmDialog from '@/components/ConfirmDialog';
import DataTable, { type Column } from '@/components/DataTable';
import MoneyText from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, GapNotice, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi } from '@/lib/hooks';
import { sumMoney } from '@/lib/money';
import { useSession } from '@/lib/session';
import type { JournalEntry, JournalLine } from '@/lib/types';

function ChainCard({ entry, current, label }: { entry: JournalEntry | undefined; current: boolean; label: string }) {
  return (
    <Paper sx={{ p: 2, flex: 1, minWidth: 220, borderColor: current ? 'secondary.main' : 'divider', borderWidth: current ? 2 : 1 }}>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      {entry ? (
        <>
          <Typography sx={{ fontWeight: 650 }}>
            {current ? entry.reference : <Link component={NextLink} href={`/ledger/journal/${entry.id}`}>{entry.reference}</Link>}
          </Typography>
          <Typography variant="body2"><BsDate ad={entry.entry_date} bs={entry.entry_date_bs} /></Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>{entry.description}</Typography>
          <Typography variant="body2" sx={{ mt: 0.5 }}><MoneyText value={sumMoney(entry.lines.map((l) => l.debit))} prefix /></Typography>
        </>
      ) : <LoadingBlock rows={2} />}
    </Paper>
  );
}

/**
 * One journal entry: lines with debit = credit check, the reversal chain (original ↔ reversal),
 * and "Reverse" with a required reason. Posted entries never change.
 */
export default function JournalEntryView() {
  const t = useT();
  const router = useRouter();
  const toast = useToast();
  const { can } = useSession();
  const { id } = useParams<{ id: string }>();
  const entry = useApi<JournalEntry>(`/journal-entries/${id}`);
  const e = entry.data;
  const original = useApi<JournalEntry>(e?.reversal_of_id ? `/journal-entries/${e.reversal_of_id}` : null);
  const reversal = useApi<JournalEntry>(e?.reversed_by_id ? `/journal-entries/${e.reversed_by_id}` : null);
  const [reversing, setReversing] = useState(false);

  if (entry.error !== undefined) return <ErrorState error={entry.error} onRetry={entry.reload} />;
  if (!e) return <LoadingBlock rows={8} />;

  const debit = sumMoney(e.lines.map((l) => l.debit));
  const credit = sumMoney(e.lines.map((l) => l.credit));
  const status = e.reversal_of_id ? 'reversal' : e.reversed_by_id ? 'reversed' : 'posted';

  const columns: Column<JournalLine>[] = [
    { key: 'line_number', header: '#', width: 48 },
    { key: 'account', header: t('ledger.account'), mobile: 'title', value: (l) => `${l.account_code} ${l.account_name}`, render: (l) => <><strong>{l.account_code}</strong> {l.account_name}</> },
    { key: 'description', header: t('common.description'), value: (l) => l.description ?? '' },
    { key: 'debit', header: t('ledger.debit'), money: true },
    { key: 'credit', header: t('ledger.credit'), money: true },
  ];

  return (
    <>
      <PageHeader
        title={<>{t('ledger.entryTitle', { reference: e.reference })} <StatusChip kind="journal" value={status} /></>}
        crumbs={[{ label: t('nav.ledger'), href: '/ledger' }, { label: t('nav.journal'), href: '/ledger/journal' }]}
        subtitle={e.description}
        meta={<>
          <Typography variant="body2">{t('ledger.entryDate')}: <BsDate ad={e.entry_date} bs={e.entry_date_bs} /></Typography>
          <Typography variant="body2">{t('ledger.postedAt')}: <BsDate ad={e.posted_at} time /></Typography>
          <Typography variant="body2">{t('ledger.postedBy')}: {e.posted_by ? t('ledger.userNo', { id: e.posted_by }) : '—'}</Typography>
        </>}
        actions={<>
          <Button startIcon={<PrintOutlined />} onClick={() => window.print()}>{t('common.print')}</Button>
          {can('journal.reverse') && status === 'posted' && (
            <Button variant="outlined" color="error" startIcon={<UndoOutlined />} onClick={() => setReversing(true)}>{t('ledger.reverse')}</Button>
          )}
        </>}
      />

      <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap', mb: 1.5 }}>
        <StatusChip kind="balance" value={debit === credit ? 'ok' : 'fail'} />
        <Typography variant="body2">{t('ledger.totalDebit')} <MoneyText value={debit} strong /></Typography>
        <Typography variant="body2">{t('ledger.totalCredit')} <MoneyText value={credit} strong /></Typography>
      </Box>
      <DataTable
        label={t('ledger.lines')}
        columns={columns}
        rows={e.lines}
        rowKey={(l) => l.line_number}
        csvName={`journal-${e.reference.replace('/', '-')}`}
        footer={(
          <TableFooter>
            <TableRow>
              <TableCell colSpan={3} sx={{ fontWeight: 650, color: 'text.primary' }}>{t('common.total')}</TableCell>
              <TableCell align="right"><MoneyText value={debit} strong /></TableCell>
              <TableCell align="right"><MoneyText value={credit} strong /></TableCell>
            </TableRow>
          </TableFooter>
        )}
      />

      {(e.reversal_of_id || e.reversed_by_id) && (
        <Box component="section" aria-label={t('ledger.chain')} sx={{ mb: 3 }}>
          <Typography variant="h2" sx={{ mb: 1.5 }}>{t('ledger.chain')}</Typography>
          <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center', flexDirection: { xs: 'column', sm: 'row' } }}>
            <ChainCard entry={e.reversal_of_id ? original.data : e} current={!e.reversal_of_id} label={t('ledger.original')} />
            <Box aria-hidden sx={{ color: 'warning.main', display: 'flex' }}>
              <ArrowForward sx={{ display: { xs: 'none', sm: 'block' } }} /><ArrowDownward sx={{ display: { sm: 'none' } }} />
            </Box>
            <ChainCard entry={e.reversal_of_id ? e : reversal.data} current={!!e.reversal_of_id} label={t('ledger.reversalEntry')} />
          </Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>{t('ledger.chainHelp')}</Typography>
        </Box>
      )}

      <GapNotice gap="G3">{t('ledger.sourceGap')}</GapNotice>

      <ConfirmDialog
        open={reversing}
        title={t('ledger.reverseTitle', { reference: e.reference })}
        confirmLabel={t('ledger.reverse')}
        danger
        reasonLabel={t('ledger.reverseReason')}
        onClose={() => setReversing(false)}
        onConfirm={async (reason) => {
          const { data } = await api<{ data: JournalEntry }>(`/journal-entries/${e.id}/reverse`, { method: 'POST', body: { reason } });
          toast.success(t('ledger.reversed', { reference: data.reference }));
          router.push(`/ledger/journal/${data.id}`);
        }}
      >
        {t('ledger.reverseBody')}
      </ConfirmDialog>
    </>
  );
}
