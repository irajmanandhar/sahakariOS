'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import DataTable, { type Column } from '@/components/DataTable';
import PageHeader from '@/components/PageHeader';
import { ErrorState, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import type { LedgerAccount, LedgerMapping, LedgerTags } from '@/lib/types';

/** Which of the cooperative's ledger accounts plays each posting role, and the report tags of accounts. */
export default function LedgerSettingsPage() {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const canEdit = can('ledger_account.manage');
  const mappings = useApi<LedgerMapping[]>('/ledger-mappings');
  const accounts = useApi<LedgerAccount[]>('/ledger-accounts');
  const tags = useApi<LedgerTags>('/ledger-account-tags');
  const [change, setChange] = useState<{ role: LedgerMapping; code: string } | null>(null);
  const [tagForm, setTagForm] = useState<{ code: string; tags: string[] }>({ code: '', tags: [] });
  const [savingTags, setSavingTags] = useState(false);
  const [tagError, setTagError] = useState<unknown>();
  const list = accounts.data ?? [];
  const codeOf = (id: number | null) => list.find((a) => a.id === id)?.code ?? '';

  const columns: Column<LedgerMapping>[] = [
    { key: 'description', header: t('ledgerRoles.role'), mobile: 'title', render: (m) => <>{m.description}<Typography variant="caption" color="text.secondary" component="div">{m.role} · {t(`ledger.types.${m.account_type}`)}</Typography></> },
    {
      key: 'account', header: t('ledger.account'), sortable: false, value: (m) => codeOf(m.ledger_account_id),
      render: (m) => (
        <TextField select fullWidth disabled={!canEdit} label={t('ledger.account')} value={codeOf(m.ledger_account_id)} onChange={(e) => setChange({ role: m, code: e.target.value })} sx={{ minWidth: 240 }} onClick={(e) => e.stopPropagation()}>
          {list.filter((a) => a.is_postable && a.type === m.account_type).map((a) => <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>)}
        </TextField>
      ),
    },
  ];

  const tagColumns: Column<LedgerTags['assigned'][number]>[] = [
    { key: 'code', header: t('ledger.account'), mobile: 'title', value: (a) => `${a.code} ${list.find((x) => x.code === a.code)?.name ?? ''}` },
    { key: 'tag', header: t('ledgerRoles.tag'), value: (a) => tags.data?.tags.find((x) => x.tag === a.tag)?.description ?? a.tag },
  ];
  const tagAccount = list.find((a) => a.code === tagForm.code);

  return (
    <>
      <PageHeader title={t('nav.ledgerRoles')} crumbs={[{ label: t('nav.settings') }]} subtitle={t('ledgerRoles.help')} />
      <Alert severity="info" sx={{ mb: 2 }}>{t('ledgerRoles.noChart')}</Alert>
      {(mappings.error ?? accounts.error) !== undefined && <ErrorState error={mappings.error ?? accounts.error} onRetry={() => { mappings.reload(); accounts.reload(); }} />}
      <DataTable label={t('nav.ledgerRoles')} columns={columns} rows={mappings.data ?? []} rowKey={(m) => m.role} loading={mappings.loading || accounts.loading} />

      <Paper component="section" aria-label={t('ledgerRoles.tagsTitle')} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
        <Typography variant="h2" sx={{ mb: 0.5 }}>{t('ledgerRoles.tagsTitle')}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('ledgerRoles.tagsHelp')}</Typography>
        {!tags.data ? (tags.error !== undefined ? <ErrorState error={tags.error} onRetry={tags.reload} /> : <LoadingBlock rows={2} />) : (
          <>
            <DataTable label={t('ledgerRoles.tagsTitle')} columns={tagColumns} rows={tags.data.assigned} rowKey={(a) => `${a.code}-${a.tag}`} emptyTitle={t('ledgerRoles.noTags')} />
            {canEdit && (
              <>
                {tagError !== undefined && <ErrorState error={tagError} />}
                <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
                  <TextField select label={t('ledger.account')} value={tagForm.code} sx={{ minWidth: 260 }}
                    onChange={(e) => setTagForm({ code: e.target.value, tags: tags.data!.assigned.filter((a) => a.code === e.target.value).map((a) => a.tag) })}>
                    {list.filter((a) => a.is_postable && ['asset', 'equity'].includes(a.type)).map((a) => <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>)}
                  </TextField>
                  <TextField select label={t('ledgerRoles.tags')} value={tagForm.tags} sx={{ minWidth: 320 }} disabled={!tagAccount}
                    slotProps={{ select: { multiple: true } }}
                    onChange={(e) => setTagForm({ ...tagForm, tags: e.target.value as unknown as string[] })}>
                    {tags.data.tags.filter((x) => x.account_type === tagAccount?.type).map((x) => <MenuItem key={x.tag} value={x.tag}>{x.description}</MenuItem>)}
                  </TextField>
                  <Button variant="contained" disabled={!tagForm.code || savingTags} onClick={async () => {
                    setSavingTags(true);
                    setTagError(undefined);
                    try {
                      tags.setData((await api<{ data: LedgerTags }>('/ledger-account-tags', { method: 'PUT', body: { account_code: tagForm.code, tags: tagForm.tags } })).data);
                      toast.success(t('ledgerRoles.tagsSaved'));
                    } catch (e) {
                      setTagError(e);
                    } finally {
                      setSavingTags(false);
                    }
                  }}>{t('ledgerRoles.saveTags')}</Button>
                </Box>
              </>
            )}
          </>
        )}
      </Paper>

      <ConfirmDialog open={!!change} title={t('ledgerRoles.changeTitle')} confirmLabel={t('common.save')} onClose={() => setChange(null)}
        onConfirm={async () => {
          if (!change) return;
          mappings.setData((await api<{ data: LedgerMapping[] }>('/ledger-mappings', { method: 'PUT', body: { role: change.role.role, account_code: change.code } })).data);
          toast.success(t('ledgerRoles.saved'));
        }}>
        {change && t('ledgerRoles.changeBody', { role: change.role.description, from: codeOf(change.role.ledger_account_id) || '—', to: change.code })}
      </ConfirmDialog>
    </>
  );
}
