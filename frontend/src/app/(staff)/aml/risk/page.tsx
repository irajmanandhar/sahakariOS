'use client';

import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import { useEffect, useState } from 'react';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { redirectForAuthError } from '@/lib/api';
import { MEMBER_SEARCH_WINDOW, recentMembers } from '@/lib/gaps';
import { useRouter } from 'next/navigation';
import type { Member } from '@/lib/types';

/**
 * High-risk and PEP members, and members never risk-assessed (AML/CFT Instruction 3).
 * The API cannot filter members by risk yet (gap G5), so this reads the newest members and filters here.
 */
export default function RiskReviewPage() {
  const t = useT();
  const router = useRouter();
  const [members, setMembers] = useState<Member[] | null>(null);
  const [error, setError] = useState<unknown>();
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let live = true;
    recentMembers(MEMBER_SEARCH_WINDOW)
      .then((m) => live && setMembers(m.filter((x) => x.is_pep || x.risk_level === 'high' || (x.risk_level === null && ['applied', 'active'].includes(x.status)))))
      .catch((e) => live && !redirectForAuthError(e, router.replace) && setError(e));
    return () => { live = false; };
  }, [attempt, router]);

  const columns: Column<Member>[] = [
    { key: 'member_number', header: t('members.number'), value: (m) => m.member_number ?? '' },
    { key: 'full_name', header: t('common.name'), mobile: 'title' },
    { key: 'risk', header: t('members.risk'), value: (m) => m.risk_level ?? '', render: (m) => <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}><StatusChip kind="risk" value={m.risk_level ?? 'none'} />{m.is_pep && <Chip color="error" label="PEP" />}</Box> },
    { key: 'pep', header: 'PEP', value: (m) => (m.is_pep ? t('common.yes') : t('common.no')) },
    { key: 'status', header: t('common.status'), render: (m) => <StatusChip kind="member" value={m.status} /> },
    { key: 'next_kyc_review_on', header: t('members.nextReview'), render: (m) => <BsDate ad={m.next_kyc_review_on} /> },
  ];

  return (
    <>
      <PageHeader title={t('nav.highRisk')} crumbs={[{ label: t('nav.aml') }]} subtitle={t('aml.riskHelp')} />
      <GapNotice gap="G5">{t('aml.riskGap', { count: MEMBER_SEARCH_WINDOW })}</GapNotice>
      <DataTable
        label={t('nav.highRisk')}
        columns={columns}
        rows={members ?? []}
        rowKey={(m) => m.id}
        rowHref={(m) => `/members/${m.id}`}
        loading={members === null && error === undefined}
        error={error}
        onRetry={() => { setError(undefined); setMembers(null); setAttempt((a) => a + 1); }}
        emptyTitle={t('aml.noRisk')}
      />
    </>
  );
}
