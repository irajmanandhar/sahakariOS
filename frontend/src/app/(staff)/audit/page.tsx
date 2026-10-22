'use client';

import CloseOutlined from '@mui/icons-material/CloseOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMemo, useState } from 'react';
import BsDate from '@/components/BsDate';
import DataTable, { type Column } from '@/components/DataTable';
import { DateField } from '@/components/fields';
import PageHeader from '@/components/PageHeader';
import { GapNotice } from '@/components/States';
import { useT } from '@/i18n';
import { auditFilter, sequenceGaps } from '@/lib/audit';
import { nepalDateTime } from '@/lib/bs';
import { useCursorList } from '@/lib/hooks';
import type { AuditLog } from '@/lib/types';

const PAIRS: [string, string][] = [['from', 'to'], ['old', 'new'], ['before', 'after']];

function Value({ value }: { value: unknown }) {
  if (value === null || value === undefined) return <Box component="span" sx={{ color: 'text.disabled' }}>null</Box>;
  if (typeof value === 'object') return <Box component="pre" sx={{ m: 0, whiteSpace: 'pre-wrap', fontSize: '0.8125rem' }}>{JSON.stringify(value, null, 2)}</Box>;
  return <span>{String(value)}</span>;
}

/** The entry's data as key/value rows; from/to style pairs are shown as a change (old struck through, new marked). */
function DataDiff({ data }: { data: Record<string, unknown> | null }) {
  const t = useT();
  if (!data) return <Typography variant="body2" color="text.secondary">{t('audit.noData')}</Typography>;
  const pair = PAIRS.find(([a, b]) => a in data && b in data);
  const rest = Object.entries(data).filter(([k]) => !pair || !pair.includes(k));
  return (
    <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'minmax(96px, auto) 1fr', gap: 1, typography: 'body2' }}>
      {pair && (
        <>
          <Typography component="dt" variant="body2" color="text.secondary">{t('audit.change')}</Typography>
          <Box component="dd" sx={{ m: 0, display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center' }}>
            <Box component="del" sx={{ bgcolor: 'error.main', color: 'error.contrastText', px: 0.75, borderRadius: 0.5 }} aria-label={t('audit.before')}><Value value={data[pair[0]]} /></Box>
            <span aria-hidden>→</span>
            <Box component="ins" sx={{ bgcolor: 'success.main', color: 'success.contrastText', px: 0.75, borderRadius: 0.5, textDecoration: 'none' }} aria-label={t('audit.after')}><Value value={data[pair[1]]} /></Box>
          </Box>
        </>
      )}
      {rest.map(([k, v]) => (
        <Box key={k} sx={{ display: 'contents' }}>
          <Typography component="dt" variant="body2" color="text.secondary" sx={{ wordBreak: 'break-word' }}>{k}</Typography>
          <Box component="dd" sx={{ m: 0, wordBreak: 'break-word' }}><Value value={v} /></Box>
        </Box>
      ))}
    </Box>
  );
}

/**
 * Tamper-evident audit trail of this cooperative (its hash chain only), newest first.
 * Filters work on the rows loaded so far (the API cannot filter yet, gap G1).
 */
export default function AuditLogPage() {
  const t = useT();
  const list = useCursorList<AuditLog>('/audit-logs');
  const [filters, setFilters] = useState({ action: '', actor: '', subject: '', from: '', to: '' });
  const [open, setOpen] = useState<AuditLog | null>(null);
  const actions = useMemo(() => [...new Set(list.rows.map((r) => r.action))].sort(), [list.rows]);
  const rows = useMemo(() => auditFilter(list.rows, filters), [list.rows, filters]);
  const gaps = useMemo(() => sequenceGaps(list.rows), [list.rows]);
  const filtered = Object.values(filters).some(Boolean);

  const columns: Column<AuditLog>[] = [
    { key: 'sequence', header: t('audit.sequence'), width: 80 },
    { key: 'occurred_at', header: t('audit.when'), mobile: 'title', value: (r) => r.occurred_at, render: (r) => <BsDate ad={r.occurred_at} time /> },
    { key: 'action', header: t('audit.action'), render: (r) => <Box component="code" sx={{ fontSize: '0.8125rem' }}>{r.action}</Box> },
    { key: 'actor', header: t('audit.actor'), value: (r) => `${r.actor_type ?? ''} ${r.actor_id ?? ''}`.trim() },
    { key: 'subject', header: t('audit.subject'), value: (r) => (r.subject_type ? `${r.subject_type} ${r.subject_id ?? ''}` : '') },
    { key: 'ip_address', header: t('audit.ip'), hidden: true },
  ];

  return (
    <>
      <PageHeader title={t('nav.auditLog')} crumbs={[{ label: t('nav.audit') }]} subtitle={t('audit.help')} />
      <Box sx={{ display: 'flex', gap: 1.5, flexWrap: 'wrap', alignItems: 'flex-start', mb: 2 }} className="no-print">
        <TextField select label={t('audit.action')} value={filters.action} onChange={(e) => setFilters({ ...filters, action: e.target.value })} sx={{ minWidth: 220 }}>
          <MenuItem value="">{t('common.all')}</MenuItem>
          {actions.map((a) => <MenuItem key={a} value={a}>{a}</MenuItem>)}
        </TextField>
        <TextField label={t('audit.actor')} placeholder="user 3" value={filters.actor} onChange={(e) => setFilters({ ...filters, actor: e.target.value })} />
        <TextField label={t('audit.subject')} placeholder="Member 12" value={filters.subject} onChange={(e) => setFilters({ ...filters, subject: e.target.value })} />
        <DateField label={t('common.from')} value={filters.from} onChange={(from) => setFilters({ ...filters, from })} />
        <DateField label={t('common.to')} value={filters.to} onChange={(to) => setFilters({ ...filters, to })} />
        {filtered && <Button sx={{ mt: 0.25 }} onClick={() => setFilters({ action: '', actor: '', subject: '', from: '', to: '' })}>{t('audit.clearFilters')}</Button>}
      </Box>
      {filtered && <Alert severity="info" sx={{ mb: 2 }}>{t('audit.filteredLoaded', { shown: rows.length, loaded: list.rows.length })}</Alert>}
      {list.rows.length > 0 && (
        gaps.length === 0
          ? <Alert severity="success" variant="outlined" sx={{ mb: 2 }}>{t('audit.sequenceOk', { first: list.rows[list.rows.length - 1].sequence, last: list.rows[0].sequence })}</Alert>
          : <Alert severity="error" sx={{ mb: 2 }}>{t('audit.sequenceGap', { gaps: gaps.map(([a, b]) => `${a}–${b}`).join(', ') })}</Alert>
      )}
      <DataTable
        label={t('nav.auditLog')}
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        onRowClick={setOpen}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        hasMore={list.hasMore}
        onLoadMore={list.loadMore}
        emptyTitle={filtered ? t('audit.noMatch') : t('audit.empty')}
        csvName="audit-log"
      />
      <GapNotice gap="G1">{t('audit.gap')}</GapNotice>

      <Drawer anchor="right" open={!!open} onClose={() => setOpen(null)} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 480 }, p: 3 } } }}>
        {open && (
          <Box role="region" aria-labelledby="audit-detail-title">
            <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 1, mb: 2 }}>
              <Box sx={{ flexGrow: 1 }}>
                <Typography variant="caption" color="text.secondary">{t('audit.entryN', { n: open.sequence })}</Typography>
                <Typography id="audit-detail-title" variant="h2" component="h2" sx={{ wordBreak: 'break-word' }}>{open.action}</Typography>
              </Box>
              <IconButton onClick={() => setOpen(null)} aria-label={t('common.close')}><CloseOutlined /></IconButton>
            </Box>
            <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'minmax(96px, auto) 1fr', gap: 1, typography: 'body2' }}>
              {[
                [t('audit.when'), <BsDate key="w" ad={open.occurred_at} time />],
                [t('audit.utc'), open.occurred_at],
                [t('audit.nepalTime'), nepalDateTime(open.occurred_at)?.time ?? '—'],
                [t('audit.actor'), `${open.actor_type ?? '—'} ${open.actor_id ?? ''}`],
                [t('audit.subject'), open.subject_type ? `${open.subject_type} ${open.subject_id ?? ''}` : '—'],
                [t('audit.ip'), open.ip_address ?? '—'],
                [t('audit.recordId'), String(open.id)],
              ].map(([k, v], i) => (
                <Box key={i} sx={{ display: 'contents' }}>
                  <Typography component="dt" variant="body2" color="text.secondary">{k}</Typography>
                  <Box component="dd" sx={{ m: 0 }}>{v}</Box>
                </Box>
              ))}
            </Box>
            <Divider sx={{ my: 2 }} />
            <Typography variant="h3" sx={{ mb: 1 }}>{t('audit.data')}</Typography>
            <DataDiff data={open.data} />
            <Divider sx={{ my: 2 }} />
            <Typography variant="h3" sx={{ mb: 1 }}>{t('audit.chain')}</Typography>
            <Typography variant="body2" color="text.secondary">{t('audit.chainHelp')}</Typography>
          </Box>
        )}
      </Drawer>
    </>
  );
}
