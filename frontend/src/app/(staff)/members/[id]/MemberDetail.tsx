'use client';

import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import BsDate from '@/components/BsDate';
import { useDigits } from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, LoadingBlock } from '@/components/States';
import { type MessageKey, useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { Member } from '@/lib/types';
import { ComplianceTab, DocumentsTab, KymTab, LoansTab, OverviewTab, SavingsTab, SharesTab } from './tabs';

type TabId = 'overview' | 'documents' | 'shares' | 'savings' | 'loans' | 'kym' | 'compliance';

/** One member: details, documents, shares, savings, loans, KYM (audited) and the AML/decision actions. */
export default function MemberDetail() {
  const t = useT();
  const digits = useDigits();
  const router = useRouter();
  const { can } = useSession();
  const { id } = useParams<{ id: string }>();
  const params = useSearchParams();
  const member = useApi<Member>(`/members/${id}`);
  const m = member.data;

  if (member.error !== undefined && !m) return <ErrorState error={member.error} onRetry={member.reload} />;
  if (!m) return <LoadingBlock rows={8} />;

  const tabs: [TabId, MessageKey, boolean][] = [
    ['overview', 'members.tab.overview', true],
    ['documents', 'members.tab.documents', true],
    ['shares', 'nav.shares', can('share.view')],
    ['savings', 'nav.savings', can('savings.view')],
    ['loans', 'nav.loans', can('loan.view')],
    ['kym', 'members.tab.kym', can('member.kyc_view')],
    ['compliance', 'members.tab.compliance', can('member.risk_assess') || can('member.decide')],
  ];
  const visible = tabs.filter(([, , show]) => show);
  const requested = params.get('tab') as TabId | null;
  const tab = visible.some(([k]) => k === requested) ? requested! : 'overview';
  const pending = m.status === 'applied';

  return (
    <>
      <PageHeader
        title={<>{m.full_name}{m.member_number && <Box component="span" sx={{ color: 'text.secondary', fontWeight: 500 }}>{t('members.noN', { n: digits(m.member_number) })}</Box>}</>}
        crumbs={[{ label: t('nav.members'), href: '/members' }]}
        subtitle={m.full_name_ne ?? undefined}
        meta={<>
          <StatusChip kind="member" value={m.status} />
          <StatusChip kind="risk" value={m.risk_level ?? 'none'} />
          {m.is_pep && <Chip color="error" label={t('members.pep')} />}
          {pending && m.days_to_decide !== null && (
            <Chip variant="outlined" color={m.days_to_decide < 0 ? 'error' : 'default'} label={m.days_to_decide < 0 ? t('members.overdue', { days: digits(-m.days_to_decide) }) : t('members.decideIn', { days: digits(m.days_to_decide) })} />
          )}
          <Box component="span" sx={{ typography: 'body2', color: 'text.secondary' }}>{t('members.appliedOn')}: <BsDate ad={m.applied_on} /></Box>
        </>}
      />
      <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 3 }} className="no-print">
        <Tabs value={tab} onChange={(_, v: TabId) => router.replace(`/members/${id}${v === 'overview' ? '' : `?tab=${v}`}`, { scroll: false })} variant="scrollable" allowScrollButtonsMobile aria-label={t('members.sections')}>
          {visible.map(([k, label]) => <Tab key={k} value={k} label={t(label)} id={`tab-${k}`} aria-controls={`panel-${k}`} />)}
        </Tabs>
      </Box>
      <Box role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        {tab === 'overview' && <OverviewTab member={m} />}
        {tab === 'documents' && <DocumentsTab member={m} onChange={member.reload} />}
        {tab === 'shares' && <SharesTab member={m} />}
        {tab === 'savings' && <SavingsTab member={m} />}
        {tab === 'loans' && <LoansTab member={m} />}
        {tab === 'kym' && <KymTab member={m} />}
        {tab === 'compliance' && <ComplianceTab member={m} onChange={member.setData} />}
      </Box>
    </>
  );
}
