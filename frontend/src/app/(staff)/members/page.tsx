'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import NextLink from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import { useDigits } from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { type MessageKey, useT } from '@/i18n';
import { useCursorList } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { Member } from '@/lib/types';

const FILTERS: Record<string, [MessageKey, string]> = {
  all: ['common.all', ''],
  applied: ['members.pending', '?status=applied'],
  active: ['status.member.active', '?status=active'],
  rejected: ['status.member.rejected', '?status=rejected'],
  review: ['nav.kymDue', '?kyc_review_due=1'],
};

/** Members and applicants. Lists never show ID numbers (those are in the audited KYM view only). */
export default function MembersPage() {
  const t = useT();
  const digits = useDigits();
  const router = useRouter();
  const { can } = useSession();
  const filter = useSearchParams().get('filter') ?? 'all';
  const key = filter in FILTERS ? filter : 'all';
  const list = useCursorList<Member>(`/members${FILTERS[key][1]}`);

  const due = (m: Member) => {
    if (m.days_to_decide === null) return <Box component="span" sx={{ color: 'text.disabled' }}>—</Box>;
    const late = m.days_to_decide < 0;
    return <Box component="span" sx={{ color: late ? 'error.main' : undefined, fontWeight: late ? 650 : undefined }}>{late ? t('members.overdue', { days: digits(-m.days_to_decide) }) : t('members.daysLeft', { days: digits(m.days_to_decide) })}</Box>;
  };

  const columns: Column<Member>[] = [
    { key: 'member_number', header: t('members.number'), value: (m) => m.member_number ?? '', width: 96 },
    { key: 'full_name', header: t('common.name'), mobile: 'title', value: (m) => `${m.full_name}${m.full_name_ne ? ` (${m.full_name_ne})` : ''}` },
    { key: 'status', header: t('common.status'), render: (m) => <StatusChip kind="member" value={m.status} /> },
    { key: 'risk_level', header: t('members.risk'), value: (m) => m.risk_level ?? '', render: (m) => <StatusChip kind="risk" value={m.risk_level ?? 'none'} /> },
    { key: 'applied_on', header: t('members.appliedOn'), render: (m) => <BsDate ad={m.applied_on} /> },
    { key: 'days_to_decide', header: t('members.decisionDue'), value: (m) => m.days_to_decide ?? '', render: due },
    { key: 'next_kyc_review_on', header: t('members.nextReview'), render: (m) => <BsDate ad={m.next_kyc_review_on} />, hidden: key !== 'review' && key !== 'active' },
    { key: 'phone', header: t('kym.phone'), hidden: true },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.members')}
        subtitle={t('members.listHelp')}
        actions={can('member.apply') && <Button variant="contained" startIcon={<AddOutlined />} component={NextLink} href="/members/new">{t('nav.newApplication')}</Button>}
      />
      <DataTable
        label={t('nav.members')}
        columns={columns}
        rows={list.rows}
        rowKey={(m) => m.id}
        rowHref={(m) => `/members/${m.id}`}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        hasMore={list.hasMore}
        onLoadMore={list.loadMore}
        quickFilter
        emptyTitle={t('members.none')}
        emptyAction={can('member.apply') && key === 'all' ? <Button variant="contained" component={NextLink} href="/members/new">{t('nav.newApplication')}</Button> : undefined}
        toolbar={(
          <ToggleButtonGroup size="small" exclusive value={key} onChange={(_, v) => v && router.replace(v === 'all' ? '/members' : `/members?filter=${v}`)} aria-label={t('common.filters')} sx={{ flexWrap: 'wrap' }}>
            {Object.entries(FILTERS).map(([k, [label]]) => <ToggleButton key={k} value={k} sx={{ px: 1.5 }}>{t(label)}</ToggleButton>)}
          </ToggleButtonGroup>
        )}
      />
    </>
  );
}
