'use client';

import ArrowDropDown from '@mui/icons-material/ArrowDropDown';
import DownloadOutlined from '@mui/icons-material/DownloadOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import ViewColumnOutlined from '@mui/icons-material/ViewColumnOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import InputAdornment from '@mui/material/InputAdornment';
import Link from '@mui/material/Link';
import ListItemText from '@mui/material/ListItemText';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableSortLabel from '@mui/material/TableSortLabel';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { useVirtualizer } from '@tanstack/react-virtual';
import NextLink from 'next/link';
import { useRouter } from 'next/navigation';
import { type ReactNode, useMemo, useRef, useState } from 'react';
import { useT } from '@/i18n';
import { compareMoney } from '@/lib/money';
import MoneyText from './MoneyText';
import { EmptyState, ErrorState, LoadingBlock } from './States';

export type Column<T> = {
  key: string;
  header: string;
  /** Cell content. Default: the value. */
  render?: (row: T) => ReactNode;
  /** Plain value for sorting, filtering and CSV. Default: row[key]. */
  value?: (row: T) => string | number | null | undefined;
  /** Decimal-string money: right-aligned, grouped, sorted exactly. */
  money?: boolean;
  align?: 'left' | 'right' | 'center';
  sortable?: boolean;
  /** Hidden until the user turns it on (column menu). */
  hidden?: boolean;
  /** On phones: 'title' is the card heading, 'hide' leaves it out. */
  mobile?: 'title' | 'hide';
  width?: number | string;
  noWrap?: boolean;
};

type Props<T> = {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  /** Accessible name of the table. */
  label: string;
  loading?: boolean;
  error?: unknown;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyBody?: ReactNode;
  emptyAction?: ReactNode;
  /** Makes the row (and its first cell, as a link for keyboard users) open this address. */
  rowHref?: (row: T) => string;
  /** Alternative to rowHref: the row (and its first cell, as a button) runs this, e.g. opens a detail panel. */
  onRowClick?: (row: T) => void;
  hasMore?: boolean;
  onLoadMore?: () => void;
  toolbar?: ReactNode;
  /** File name for "Export CSV" of the loaded rows; omit to hide the button. */
  csvName?: string;
  quickFilter?: boolean;
  footer?: ReactNode;
  maxHeight?: number | string;
  rowSx?: (row: T) => object | undefined;
};

const VIRTUALIZE_OVER = 150;

function plain<T>(column: Column<T>, row: T): string | number | null | undefined {
  return column.value ? column.value(row) : ((row as Record<string, unknown>)[column.key] as string | number | null | undefined);
}

function csvCell(value: unknown): string {
  let text = value === null || value === undefined ? '' : String(value);
  if (/^[=+\-@\t\r]/.test(text) && !/^-?\d+(\.\d+)?$/.test(text)) text = `'${text}`; // no spreadsheet formulas; negative amounts stay numbers
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

export function downloadCsv(name: string, header: string[], rows: unknown[][]): void {
  const body = [header, ...rows].map((r) => r.map(csvCell).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob(['﻿', body], { type: 'text/csv;charset=utf-8' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: name.endsWith('.csv') ? name : `${name}.csv` });
  a.click();
  URL.revokeObjectURL(url);
}

/**
 * Data-heavy table: sticky header, client sort and quick filter over the loaded rows, column
 * visibility, CSV export, cursor "Load more", loading/empty/error states, virtualised long lists,
 * and cards instead of columns on phones.
 */
export default function DataTable<T>(props: Props<T>) {
  const { columns, rows, rowKey, label, loading, error, onRetry, rowHref, onRowClick, hasMore, onLoadMore, toolbar, csvName, quickFilter, footer, rowSx } = props;
  const t = useT();
  const router = useRouter();
  const theme = useTheme();
  const phone = useMediaQuery(theme.breakpoints.down('sm'));
  const [hidden, setHidden] = useState(() => new Set(columns.filter((c) => c.hidden).map((c) => c.key)));
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null);
  const [filter, setFilter] = useState('');
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const visible = columns.filter((c) => !hidden.has(c.key));

  const shown = useMemo(() => {
    let list = rows;
    const needle = filter.trim().toLowerCase();
    if (needle) list = list.filter((r) => columns.some((c) => String(plain(c, r) ?? '').toLowerCase().includes(needle)));
    if (sort) {
      const column = columns.find((c) => c.key === sort.key);
      if (column) {
        const sign = sort.dir === 'asc' ? 1 : -1;
        list = [...list].sort((a, b) => {
          const x = plain(column, a);
          const y = plain(column, b);
          if (x === null || x === undefined || x === '') return 1;
          if (y === null || y === undefined || y === '') return -1;
          if (column.money) return sign * compareMoney(String(x), String(y));
          if (typeof x === 'number' && typeof y === 'number') return sign * (x - y);
          return sign * String(x).localeCompare(String(y), undefined, { numeric: true });
        });
      }
    }
    return list;
  }, [rows, columns, filter, sort]);

  const virtual = !phone && shown.length > VIRTUALIZE_OVER;
  // eslint-disable-next-line react-hooks/incompatible-library -- the virtualizer is only read during render
  const virtualizer = useVirtualizer({ count: virtual ? shown.length : 0, getScrollElement: () => scrollRef.current, estimateSize: () => 37, overscan: 12 });
  const items = virtual ? virtualizer.getVirtualItems() : null;
  const padTop = items?.length ? items[0].start : 0;
  const padBottom = items?.length ? virtualizer.getTotalSize() - items[items.length - 1].end : 0;
  const slice = items ? items.map((i) => shown[i.index]) : shown;

  const cell = (c: Column<T>, row: T, first: boolean) => {
    const content = c.render ? c.render(row) : c.money ? <MoneyText value={plain(c, row) as string} /> : (plain(c, row) ?? '—');
    if (first && rowHref) {
      return <Link component={NextLink} href={rowHref(row)} underline="hover" color="inherit" sx={{ fontWeight: 600 }} onClick={(e) => e.stopPropagation()}>{content}</Link>;
    }
    if (first && onRowClick) {
      return <Link component="button" type="button" underline="hover" color="inherit" sx={{ fontWeight: 600, textAlign: 'left', font: 'inherit' }} onClick={(e) => { e.stopPropagation(); onRowClick(row); }}>{content}</Link>;
    }
    return content;
  };

  const exportCsv = () => downloadCsv(csvName!, visible.map((c) => c.header), shown.map((r) => visible.map((c) => plain(c, r))));

  const bar = (toolbar || quickFilter || csvName || columns.length > 4) && (
    <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center', p: 1.5, borderBottom: 1, borderColor: 'divider' }} className="no-print">
      {toolbar}
      {quickFilter && (
        <TextField
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          placeholder={t('common.search')}
          slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchOutlined fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': `${t('common.search')}: ${label}` } }}
          sx={{ minWidth: 200 }}
        />
      )}
      <Box sx={{ flexGrow: 1 }} />
      {!phone && columns.length > 4 && (
        <Button size="small" startIcon={<ViewColumnOutlined />} endIcon={<ArrowDropDown />} onClick={(e) => setMenu(e.currentTarget)} aria-haspopup="menu">
          {t('common.columns')}
        </Button>
      )}
      {csvName && <Button size="small" startIcon={<DownloadOutlined />} onClick={exportCsv} disabled={shown.length === 0}>{t('common.exportCsv')}</Button>}
      <Menu anchorEl={menu} open={!!menu} onClose={() => setMenu(null)}>
        {columns.map((c) => (
          <MenuItem key={c.key} dense onClick={() => setHidden((h) => { const n = new Set(h); if (n.has(c.key)) n.delete(c.key); else n.add(c.key); return n; })}>
            <Checkbox size="small" checked={!hidden.has(c.key)} sx={{ p: 0, mr: 1 }} tabIndex={-1} />
            <ListItemText>{c.header}</ListItemText>
          </MenuItem>
        ))}
      </Menu>
    </Box>
  );

  const more = hasMore && onLoadMore && (
    <Box sx={{ p: 1.5, textAlign: 'center', borderTop: 1, borderColor: 'divider' }} className="no-print">
      <Button onClick={onLoadMore} disabled={loading}>{loading ? t('common.loading') : t('common.loadMore')}</Button>
    </Box>
  );

  let body: ReactNode;
  if (error !== undefined && rows.length === 0) body = <Box sx={{ px: 2 }}><ErrorState error={error} onRetry={onRetry} /></Box>;
  else if (loading && rows.length === 0) body = <Box sx={{ px: 2 }}><LoadingBlock /></Box>;
  else if (shown.length === 0) body = <EmptyState title={props.emptyTitle ?? t('common.noRows')} action={props.emptyAction}>{props.emptyBody}</EmptyState>;
  else if (phone) {
    const title = columns.find((c) => c.mobile === 'title') ?? visible[0];
    body = (
      <Box component="ul" aria-label={label} sx={{ listStyle: 'none', m: 0, p: 0 }}>
        {shown.map((row) => (
          <Box component="li" key={rowKey(row)} sx={{ p: 1.5, borderBottom: 1, borderColor: 'divider', ...rowSx?.(row) }}>
            <Typography variant="subtitle2" component="div" sx={{ mb: 0.5 }}>{cell(title, row, true)}</Typography>
            <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', columnGap: 1.5, rowGap: 0.25 }}>
              {visible.filter((c) => c !== title && c.mobile !== 'hide').map((c) => (
                <Box key={c.key} sx={{ display: 'contents' }}>
                  <Typography component="dt" variant="caption" color="text.secondary">{c.header}</Typography>
                  <Typography component="dd" variant="body2" sx={{ m: 0, textAlign: c.money ? 'right' : undefined }}>{cell(c, row, false)}</Typography>
                </Box>
              ))}
            </Box>
          </Box>
        ))}
      </Box>
    );
  } else {
    body = (
      <TableContainer ref={scrollRef} sx={{ maxHeight: props.maxHeight ?? (virtual ? '70vh' : undefined), '@media print': { maxHeight: 'none', overflow: 'visible' } }}>
        <Table size="small" stickyHeader aria-label={label}>
          <TableHead>
            <TableRow>
              {visible.map((c) => (
                <TableCell key={c.key} align={c.align ?? (c.money ? 'right' : 'left')} sx={{ width: c.width }} sortDirection={sort?.key === c.key ? sort.dir : false}>
                  {c.sortable === false ? c.header : (
                    <TableSortLabel active={sort?.key === c.key} direction={sort?.key === c.key ? sort.dir : 'asc'} onClick={() => setSort((s) => (s?.key === c.key && s.dir === 'asc' ? { key: c.key, dir: 'desc' } : s?.key === c.key ? null : { key: c.key, dir: 'asc' }))}>
                      {c.header}
                    </TableSortLabel>
                  )}
                </TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {padTop > 0 && <TableRow aria-hidden><TableCell colSpan={visible.length} sx={{ height: padTop, p: 0, border: 0 }} /></TableRow>}
            {slice.map((row) => (
              <TableRow
                key={rowKey(row)}
                hover={!!(rowHref || onRowClick)}
                onClick={rowHref ? () => router.push(rowHref(row)) : onRowClick ? () => onRowClick(row) : undefined}
                sx={{ cursor: rowHref || onRowClick ? 'pointer' : undefined, ...rowSx?.(row) }}
              >
                {visible.map((c, i) => (
                  <TableCell key={c.key} align={c.align ?? (c.money ? 'right' : 'left')} sx={{ whiteSpace: c.noWrap || c.money ? 'nowrap' : undefined }}>
                    {cell(c, row, i === 0)}
                  </TableCell>
                ))}
              </TableRow>
            ))}
            {padBottom > 0 && <TableRow aria-hidden><TableCell colSpan={visible.length} sx={{ height: padBottom, p: 0, border: 0 }} /></TableRow>}
          </TableBody>
          {footer}
        </Table>
      </TableContainer>
    );
  }

  return (
    <Paper sx={{ overflow: 'hidden', mb: 3 }}>
      {bar}
      {error !== undefined && rows.length > 0 && <Box sx={{ px: 2 }}><ErrorState error={error} onRetry={onRetry} /></Box>}
      {body}
      {more}
    </Paper>
  );
}
