'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Collapse from '@mui/material/Collapse';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type FormEvent, useMemo, useState } from 'react';
import DataTable, { type Column } from '@/components/DataTable';
import { SubmitButton } from '@/components/fields';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { FormError } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { fieldError, useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import { useSession } from '@/lib/session';
import { type AccountRow as Row, treeOrder } from '@/lib/ledger';
import type { LedgerAccount, LedgerAccountType } from '@/lib/types';

const TYPES: LedgerAccountType[] = ['asset', 'liability', 'equity', 'income', 'expense'];
const empty = { code: '', name: '', type: 'asset' as LedgerAccountType, is_postable: true, parent_code: '' };

/** The cooperative's own chart of accounts (SahakariOS ships none; it is the accountant's). */
export default function ChartOfAccountsPage() {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const accounts = useApi<LedgerAccount[]>('/ledger-accounts');
  const rows = useMemo(() => treeOrder(accounts.data ?? []), [accounts.data]);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(empty);
  useUnsavedChanges(open && (form.code !== '' || form.name !== ''));

  const create = useSubmit('account', async () => {
    const { data } = await api<{ data: LedgerAccount }>('/ledger-accounts', { method: 'POST', body: { ...form, parent_code: form.parent_code || null } });
    return data;
  });

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const created = await create.submit();
    if (created) {
      toast.success(t('ledger.accountCreated', { code: created.code }));
      setForm(empty);
      setOpen(false);
      accounts.reload();
    }
  }

  const columns: Column<Row>[] = [
    { key: 'code', header: t('common.code'), mobile: 'title', sortable: false, render: (a) => <Box component="span" sx={{ pl: a.depth * 2.5, fontWeight: a.is_postable ? 400 : 650 }}>{a.code}</Box> },
    { key: 'name', header: t('common.name'), sortable: false, render: (a) => <Box component="span" sx={{ fontWeight: a.is_postable ? 400 : 650 }}>{a.name}</Box> },
    { key: 'type', header: t('common.type'), sortable: false, render: (a) => t(`ledger.types.${a.type}`) },
    { key: 'normal_side', header: t('ledger.normalSide'), sortable: false, render: (a) => t(a.normal_side === 'debit' ? 'ledger.debit' : 'ledger.credit') },
    { key: 'is_postable', header: t('ledger.postable'), sortable: false, value: (a) => (a.is_postable ? t('common.yes') : t('ledger.group')) },
    { key: 'status', header: t('common.status'), sortable: false, render: (a) => <StatusChip kind="account" value={a.status} /> },
  ];

  return (
    <>
      <PageHeader
        title={t('nav.chartOfAccounts')}
        crumbs={[{ label: t('nav.ledger'), href: '/ledger' }]}
        subtitle={t('ledger.coaHelp')}
        actions={can('ledger_account.manage') && !open && <Button variant="contained" startIcon={<AddOutlined />} onClick={() => setOpen(true)}>{t('ledger.newAccount')}</Button>}
      />
      <Collapse in={open} unmountOnExit>
        <Paper component="form" method="post" noValidate onSubmit={onSubmit} sx={{ p: { xs: 2, sm: 3 }, mb: 3 }}>
          <Typography variant="h2" sx={{ mb: 2 }}>{t('ledger.newAccount')}</Typography>
          <FormError error={create.error} />
          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: '1fr 2fr', md: '1fr 2fr 1fr 1.5fr' } }}>
            <TextField required label={t('common.code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} {...fieldError(create.fieldErrors, 'code')} />
            <TextField required label={t('common.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} {...fieldError(create.fieldErrors, 'name')} />
            <TextField select required label={t('common.type')} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as LedgerAccountType })} {...fieldError(create.fieldErrors, 'type')}>
              {TYPES.map((ty) => <MenuItem key={ty} value={ty}>{t(`ledger.types.${ty}`)}</MenuItem>)}
            </TextField>
            <TextField select label={t('ledger.parent')} value={form.parent_code} onChange={(e) => setForm({ ...form, parent_code: e.target.value })} {...fieldError(create.fieldErrors, 'parent_code')}>
              <MenuItem value="">{t('common.none')}</MenuItem>
              {rows.filter((a) => !a.is_postable && a.type === form.type).map((a) => <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>)}
            </TextField>
          </Box>
          <FormControlLabel sx={{ mt: 1 }} control={<Checkbox checked={form.is_postable} onChange={(e) => setForm({ ...form, is_postable: e.target.checked })} />} label={t('ledger.postableHelp')} />
          <Box sx={{ display: 'flex', gap: 1, mt: 2 }}>
            <SubmitButton submitting={create.submitting}>{t('common.create')}</SubmitButton>
            <Button onClick={() => { setOpen(false); setForm(empty); }}>{t('common.cancel')}</Button>
          </Box>
        </Paper>
      </Collapse>
      <DataTable
        label={t('nav.chartOfAccounts')}
        columns={columns}
        rows={rows}
        rowKey={(a) => a.id}
        rowHref={(a) => `/ledger/accounts/${a.id}`}
        loading={accounts.loading}
        error={accounts.error}
        onRetry={accounts.reload}
        emptyTitle={t('ledger.noAccounts')}
        emptyBody={t('ledger.noAccountsHelp')}
        csvName="chart-of-accounts"
        quickFilter
      />
    </>
  );
}
