'use client';

import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { useT } from '@/i18n';
import { useApi } from '@/lib/hooks';
import type { SmsMessage } from '@/lib/types';

/** Member SMS outbox (latest 100). Delivered by the scheduler (php artisan sms:send); the provider is still to be chosen. */
export default function SmsPage() {
  const t = useT();
  const sms = useApi<SmsMessage[]>('/sms-messages');
  const columns: Column<SmsMessage>[] = [
    { key: 'created_at', header: t('sms.queued'), mobile: 'title', render: (m) => <BsDate ad={m.created_at} time /> },
    { key: 'body', header: t('sms.message') },
    { key: 'status', header: t('common.status'), render: (m) => <StatusChip kind="sms" value={m.status} /> },
    { key: 'attempts', header: t('sms.attempts'), align: 'right' },
    { key: 'sent_at', header: t('sms.sent'), render: (m) => <BsDate ad={m.sent_at} time /> },
  ];
  return (
    <>
      <PageHeader title={t('nav.sms')} crumbs={[{ label: t('nav.settings') }]} subtitle={t('sms.help')} />
      <DataTable label={t('nav.sms')} columns={columns} rows={sms.data ?? []} rowKey={(m) => m.id} loading={sms.loading} error={sms.error} onRetry={sms.reload} emptyTitle={t('sms.none')} quickFilter />
    </>
  );
}
