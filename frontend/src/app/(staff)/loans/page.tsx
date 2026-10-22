'use client';

import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import { useRouter, useSearchParams } from 'next/navigation';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { useT } from '@/i18n';
import { useCursorList } from '@/lib/hooks';
import type { Loan, LoanStatus } from '@/lib/types';

const STATUSES: (LoanStatus | 'all')[] = ['all', 'applied', 'approved', 'disbursed', 'closed', 'rejected'];

/** Every loan of the cooperative, newest first, filtered by status on the server. */
export default function LoansPage() {
  const t = useT();
  const router = useRouter();
  const requested = useSearchParams().get('status') as LoanStatus | null;
  const status = requested && STATUSES.includes(requested) ? requested : 'all';
  const list = useCursorList<Loan>(`/loans${status === 'all' ? '' : `?status=${status}`}`);

  const columns: Column<Loan>[] = [
    { key: 'loan_number', header: t('loans.number'), width: 80 },
    { key: 'member', header: t('aml.member'), mobile: 'title', value: (l) => `${l.member.member_number ?? ''} ${l.member.full_name}`.trim() },
    { key: 'product', header: t('loans.product'), value: (l) => `${l.product.code} · ${l.product.name}` },
    { key: 'status', header: t('common.status'), render: (l) => <StatusChip kind="loan" value={l.status} /> },
    { key: 'classification', header: t('loans.classification'), value: (l) => (l.status === 'disbursed' ? l.classification ?? '' : ''), render: (l) => (l.status === 'disbursed' ? <StatusChip kind="classification" value={l.classification} /> : '—') },
    { key: 'principal', header: t('loans.principal'), money: true },
    { key: 'outstanding_principal', header: t('loans.outstanding'), money: true },
    { key: 'applied_on', header: t('members.appliedOn'), render: (l) => <BsDate ad={l.applied_on} />, hidden: true },
    { key: 'disbursed_on', header: t('loans.disbursedOn'), render: (l) => <BsDate ad={l.disbursed_on} /> },
  ];

  return (
    <>
      <PageHeader title={t('nav.loanList')} crumbs={[{ label: t('nav.loans') }]} subtitle={t('loans.listHelp')} />
      <DataTable
        label={t('nav.loanList')}
        columns={columns}
        rows={list.rows}
        rowKey={(l) => l.id}
        rowHref={(l) => `/loans/${l.id}`}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        hasMore={list.hasMore}
        onLoadMore={list.loadMore}
        quickFilter
        csvName={`loans-${status}`}
        emptyTitle={t('loans.none')}
        toolbar={(
          <ToggleButtonGroup size="small" exclusive value={status} onChange={(_, v) => v && router.replace(v === 'all' ? '/loans' : `/loans?status=${v}`)} aria-label={t('common.status')} sx={{ flexWrap: 'wrap' }}>
            {STATUSES.map((s) => <ToggleButton key={s} value={s} sx={{ px: 1.5 }}>{s === 'all' ? t('common.all') : t(`status.loan.${s}`)}</ToggleButton>)}
          </ToggleButtonGroup>
        )}
      />
    </>
  );
}
