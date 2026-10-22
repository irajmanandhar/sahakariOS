'use client';

import Chip from '@mui/material/Chip';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import type { Branch } from '@/lib/types';

/** Branches of this cooperative (read-only: the API has no branch create/edit yet, gap G10). */
export default function BranchesPage() {
  const t = useT();
  const branches = useApi<Branch[]>('/branches');
  const columns: Column<Branch>[] = [
    { key: 'code', header: t('common.code') },
    { key: 'name', header: t('common.name'), mobile: 'title', render: (b) => <>{b.name} {b.is_head_office && <Chip variant="outlined" label={t('branches.headOffice')} sx={{ ml: 1 }} />}</> },
    { key: 'status', header: t('common.status'), render: (b) => <StatusChip kind="account" value={b.status} /> },
  ];
  return (
    <>
      <PageHeader title={t('nav.branches')} crumbs={[{ label: t('nav.settings') }]} subtitle={t('branches.help')} />
      <DataTable label={t('nav.branches')} columns={columns} rows={branches.data ?? []} rowKey={(b) => b.id} loading={branches.loading} error={branches.error} onRetry={branches.reload} />
      <GapNotice gap="G10">{t('branches.gap')}</GapNotice>
    </>
  );
}
