'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import Button from '@mui/material/Button';
import NextLink from 'next/link';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { useCursorList } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { JournalSummary } from '@/lib/types';

/** Every posted journal entry, newest first. Entries are immutable; corrections are reversals. */
export default function JournalListPage() {
  const t = useT();
  const { can } = useSession();
  const list = useCursorList<JournalSummary>('/journal-entries');

  const columns: Column<JournalSummary>[] = [
    { key: 'reference', header: t('ledger.reference'), mobile: 'title', noWrap: true },
    { key: 'entry_date', header: t('common.date'), render: (e) => <BsDate ad={e.entry_date} bs={e.entry_date_bs} /> },
    { key: 'description', header: t('common.description') },
    { key: 'kind', header: t('common.status'), value: (e) => (e.reversal_of_id ? 'reversal' : 'posted'), render: (e) => <StatusChip kind="journal" value={e.reversal_of_id ? 'reversal' : 'posted'} /> },
    { key: 'total', header: t('common.amount'), money: true },
    { key: 'posted_at', header: t('ledger.postedAt'), hidden: true, render: (e) => <BsDate ad={e.posted_at} time /> },
    { key: 'posted_by', header: t('ledger.postedBy'), hidden: true },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.journal')}
        crumbs={[{ label: t('nav.ledger'), href: '/ledger' }]}
        subtitle={t('ledger.journalHelp')}
        actions={can('journal.post') && <Button variant="contained" startIcon={<AddOutlined />} component={NextLink} href="/ledger/journal/new">{t('nav.newJournal')}</Button>}
      />
      <DataTable
        label={t('nav.journal')}
        columns={columns}
        rows={list.rows}
        rowKey={(e) => e.id}
        rowHref={(e) => `/ledger/journal/${e.id}`}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        hasMore={list.hasMore}
        onLoadMore={list.loadMore}
        emptyTitle={t('ledger.noEntries')}
        csvName="journal-entries"
        quickFilter
      />
      <GapNotice gap="G3">{t('ledger.journalFilterGap')}</GapNotice>
    </>
  );
}
