'use client';

import ArrowForward from '@mui/icons-material/ArrowForward';
import LockClockOutlined from '@mui/icons-material/LockClockOutlined';
import PlayCircleOutlined from '@mui/icons-material/PlayCircleOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Grid from '@mui/material/Grid';
import Link from '@mui/material/Link';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { type ReactNode, useState } from 'react';
import { useBsText } from '@/components/BsDate';
import ConfirmDialog from '@/components/ConfirmDialog';
import MoneyText, { useDigits } from '@/components/MoneyText';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { ErrorState, GapNotice } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi } from '@/lib/hooks';
import { toPaisa } from '@/lib/money';
import { useSession } from '@/lib/session';
import type { BusinessDay, Member, Report, ThresholdTransaction } from '@/lib/types';

const CLASSES = ['pass', 'substandard', 'doubtful', 'loss'] as const;

function Panel({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <Paper component="section" aria-label={title} sx={{ p: 2.5, height: '100%' }}>
      <Box sx={{ display: 'flex', alignItems: 'center', mb: 1.5, gap: 1 }}>
        <Typography variant="h2" sx={{ flexGrow: 1, fontSize: '1rem' }}>{title}</Typography>
        {action}
      </Box>
      {children}
    </Paper>
  );
}

function Figure({ label, value, note, href, loading }: { label: string; value: ReactNode; note?: ReactNode; href?: string; loading?: boolean }) {
  const t = useT();
  return (
    <Paper sx={{ p: 2, height: '100%', display: 'flex', flexDirection: 'column', gap: 0.5 }}>
      <Typography variant="body2" color="text.secondary">{label}</Typography>
      <Typography component="div" sx={{ fontSize: '1.5rem', fontWeight: 650, letterSpacing: '-0.01em', fontVariantNumeric: 'tabular-nums' }}>
        {loading ? <Skeleton width="70%" /> : value}
      </Typography>
      {note && <Typography variant="caption" color="text.secondary">{note}</Typography>}
      {href && (
        <Link component={NextLink} href={href} variant="caption" underline="hover" sx={{ mt: 'auto', pt: 0.5, display: 'inline-flex', alignItems: 'center', gap: 0.25 }}>
          {t('dashboard.drill')} <ArrowForward sx={{ fontSize: 14 }} />
        </Link>
      )}
    </Paper>
  );
}

function BusinessDayPanel() {
  const t = useT();
  const toast = useToast();
  const bs = useBsText();
  const digits = useDigits();
  const { businessDay, setBusinessDay, can } = useSession();
  const [confirm, setConfirm] = useState<'open' | 'close' | null>(null);

  async function change(action: 'open' | 'close') {
    const { data } = await api<{ data: BusinessDay }>(`/business-day/${action}`, { method: 'POST' });
    setBusinessDay(data.status === 'open' ? data : null);
    toast.success(action === 'open' ? t('dashboard.dayOpened') : t('dashboard.dayClosed'));
  }

  return (
    <Box component="section" aria-label={t('dashboard.businessDay')} sx={{ bgcolor: 'sidebar.bg', color: 'sidebar.fg', borderRadius: 2, p: { xs: 2.5, sm: 3 }, height: '100%', display: 'flex', flexDirection: 'column', gap: 1.5, position: 'relative', overflow: 'hidden' }}>
      <Box aria-hidden sx={{ position: 'absolute', right: -40, top: -40, width: 160, height: 160, borderRadius: '50%', border: '18px solid', borderColor: businessDay ? 'secondary.main' : 'sidebar.hover', opacity: 0.35 }} />
      <Typography variant="body2" sx={{ color: 'sidebar.muted' }}>{t('dashboard.businessDay')}</Typography>
      {businessDay ? (
        <>
          <Typography component="p" sx={{ fontSize: { xs: '1.75rem', sm: '2.25rem' }, fontWeight: 700, lineHeight: 1.1, letterSpacing: '-0.02em' }}>
            {bs(businessDay.date, null, 'long') ?? businessDay.date_bs_text}
          </Typography>
          <Typography sx={{ color: 'sidebar.muted' }}>{digits(businessDay.date)} {t('common.ad')} · {t('shell.fiscalYear', { fy: digits(businessDay.fiscal_year) })}</Typography>
          <Chip label={t('dashboard.dayIsOpen')} color="secondary" icon={<PlayCircleOutlined />} sx={{ alignSelf: 'flex-start' }} />
        </>
      ) : (
        <>
          <Typography component="p" sx={{ fontSize: { xs: '1.5rem', sm: '1.875rem' }, fontWeight: 700, lineHeight: 1.15 }}>{t('dashboard.noDay')}</Typography>
          <Typography sx={{ color: 'sidebar.muted', maxWidth: 420 }}>{t('dashboard.noDayHelp')}</Typography>
        </>
      )}
      <Box sx={{ mt: 'auto', display: 'flex', gap: 1, flexWrap: 'wrap' }}>
        {!businessDay && can('business_day.open') && (
          <Button variant="contained" color="secondary" startIcon={<PlayCircleOutlined />} onClick={() => setConfirm('open')}>{t('dashboard.openDay')}</Button>
        )}
        {businessDay && can('business_day.close') && (
          <Button variant="outlined" startIcon={<LockClockOutlined />} onClick={() => setConfirm('close')} sx={{ color: 'sidebar.fg', borderColor: 'sidebar.muted' }}>{t('dashboard.closeDay')}</Button>
        )}
      </Box>
      <ConfirmDialog
        open={confirm === 'open'}
        title={t('dashboard.openDayTitle')}
        confirmLabel={t('dashboard.openDay')}
        onConfirm={() => change('open')}
        onClose={() => setConfirm(null)}
      >
        {t('dashboard.openDayBody')}
      </ConfirmDialog>
      <ConfirmDialog
        open={confirm === 'close'}
        title={t('dashboard.closeDayTitle', { date: businessDay?.date_bs_text ?? '' })}
        confirmLabel={t('dashboard.closeDay')}
        danger
        onConfirm={() => change('close')}
        onClose={() => setConfirm(null)}
      >
        {t('dashboard.closeDayBody')}
      </ConfirmDialog>
    </Box>
  );
}

/** Percentage width for a bar, from exact paisa (display only). */
function share(part: string, total: string): number {
  const whole = toPaisa(total);
  return whole === 0n ? 0 : Number((toPaisa(part) * 1000n) / whole) / 10;
}

function Classification({ report }: { report: Report }) {
  const t = useT();
  const digits = useDigits();
  const section = report.sections[0];
  return (
    <>
    <Box component="ul" sx={{ listStyle: 'none', m: 0, p: 0, display: 'grid', gap: 1.25 }}>
      {CLASSES.map((c, i) => {
        const row = section?.rows[i];
        return (
          <Box component="li" key={c}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
              <StatusChip kind="classification" value={c} />
              <Typography variant="caption" color="text.secondary" sx={{ flexGrow: 1 }}>{row?.note ? digits(row.note) : ''}</Typography>
              <MoneyText value={row?.amount ?? '0.00'} />
            </Box>
            <Box aria-hidden sx={{ height: 6, borderRadius: 3, bgcolor: 'action.hover', overflow: 'hidden' }}>
              <Box sx={{ height: '100%', width: `${row && section.total ? share(row.amount, section.total) : 0}%`, bgcolor: c === 'pass' ? 'success.main' : c === 'substandard' ? 'warning.main' : 'error.main', opacity: c === 'doubtful' ? 0.7 : 1 }} />
            </Box>
          </Box>
        );
      })}
    </Box>
    <Typography variant="body2" sx={{ display: 'flex', justifyContent: 'space-between', pt: 1, mt: 1.25, borderTop: 1, borderColor: 'divider' }}>
      <span>{t('dashboard.totalOutstanding')}</span><MoneyText value={section?.total ?? '0.00'} strong />
    </Typography>
    </>
  );
}

function Queue() {
  const t = useT();
  const digits = useDigits();
  const { can } = useSession();
  const pending = useApi<Member[]>(can('member.view') ? '/members?status=applied' : null);
  const review = useApi<Member[]>(can('member.view') ? '/members?kyc_review_due=1' : null);
  const ttr = useApi<ThresholdTransaction[]>(can('aml.view') ? '/reports/threshold-transactions' : null);
  const count = (rows: unknown[] | undefined) => (rows === undefined ? '…' : digits(rows.length >= 50 ? '50+' : rows.length));
  const soonest = ttr.data?.map((r) => r.report_by).sort()[0];
  const overdue = pending.data?.filter((m) => (m.days_to_decide ?? 1) < 0).length ?? 0;

  const items: { show: boolean; href: string; primary: string; secondary: string; urgent?: boolean }[] = [
    { show: can('member.view'), href: '/members?filter=applied', primary: t('dashboard.pendingApplications', { count: count(pending.data) }), secondary: overdue ? t('dashboard.overdueDecisions', { count: digits(overdue) }) : t('dashboard.decideWithin'), urgent: overdue > 0 },
    { show: can('member.view'), href: '/members?filter=review', primary: t('dashboard.kymDue', { count: count(review.data) }), secondary: t('dashboard.kymDueHelp') },
    { show: can('aml.view'), href: '/aml/threshold', primary: t('dashboard.thresholdDue', { count: count(ttr.data) }), secondary: soonest ? t('dashboard.reportBy', { date: soonest }) : t('dashboard.thresholdHelp'), urgent: !!soonest },
  ];
  const error = pending.error ?? review.error ?? ttr.error;

  return (
    <>
      {error !== undefined && <ErrorState error={error} onRetry={() => { pending.reload(); review.reload(); ttr.reload(); }} />}
      <List disablePadding>
        {items.filter((i) => i.show).map((i) => (
          <li key={i.href}>
            <ListItemButton component={NextLink} href={i.href} sx={{ borderRadius: 1.5, px: 1.5 }}>
              <ListItemText primary={i.primary} secondary={i.secondary} slotProps={{ secondary: { sx: { color: i.urgent ? 'error.main' : undefined } } }} />
              <ArrowForward fontSize="small" color="action" />
            </ListItemButton>
          </li>
        ))}
      </List>
    </>
  );
}

/** Staff home: business day, key figures, work queues and integrity status. Every figure links to its source. */
export default function DashboardPage() {
  const t = useT();
  const { can } = useSession();
  const reports = can('report.view');
  const portfolio = useApi<Report>(reports ? '/reports/statements/portfolio_ratios' : null);
  const liquidity = useApi<Report>(reports ? '/reports/statements/liquidity' : null);
  const classification = useApi<Report>(reports ? '/reports/statements/loan_classification' : null);
  const ratio = liquidity.data?.checks[0];
  const digits = useDigits();

  return (
    <>
      <PageHeader title={t('nav.dashboard')} subtitle={t('dashboard.subtitle')} />
      <Grid container spacing={2.5}>
        <Grid size={{ xs: 12, lg: 5 }}><BusinessDayPanel /></Grid>
        <Grid size={{ xs: 12, lg: 7 }}>
          {reports ? (
            <Grid container spacing={2} sx={{ height: '100%' }}>
              <Grid size={{ xs: 12, sm: 6 }}>
                <Figure label={t('dashboard.totalSavings')} loading={portfolio.loading} value={<MoneyText value={portfolio.data?.sections[0]?.rows[0]?.amount} prefix />} note={t('dashboard.fromSubledger')} href="/reports/portfolio_ratios" />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <Figure label={t('dashboard.loansOutstanding')} loading={portfolio.loading} value={<MoneyText value={portfolio.data?.sections[1]?.rows[0]?.amount} prefix />} note={t('dashboard.disbursedLoans')} href="/loans?status=disbursed" />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <Figure
                  label={t('dashboard.liquidity')}
                  loading={liquidity.loading}
                  value={ratio ? <Box component="span" sx={{ display: 'inline-flex', gap: 1, alignItems: 'center' }}>{digits(ratio.value)}<StatusChip kind="check" value={ratio.ok ? 'ok' : 'fail'} /></Box> : '—'}
                  note={ratio && t('dashboard.liquidityNote', { requirement: digits(ratio.requirement) })}
                  href="/reports/liquidity"
                />
              </Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <Figure label={t('dashboard.members')} value="—" note={t('dashboard.membersGap')} href={can('member.view') ? '/members' : undefined} />
              </Grid>
            </Grid>
          ) : (
            <Alert severity="info">{t('dashboard.noReportAccess')}</Alert>
          )}
          {(portfolio.error ?? liquidity.error) !== undefined && <ErrorState error={portfolio.error ?? liquidity.error} onRetry={() => { portfolio.reload(); liquidity.reload(); }} />}
        </Grid>
        {reports && (
          <Grid size={{ xs: 12, md: 6 }}>
            <Panel title={t('dashboard.loansByClass')} action={<Button size="small" component={NextLink} href="/reports/loan_classification">{t('dashboard.drill')}</Button>}>
              {classification.error !== undefined ? <ErrorState error={classification.error} onRetry={classification.reload} /> : classification.data ? <Classification report={classification.data} /> : <Skeleton height={160} />}
            </Panel>
          </Grid>
        )}
        <Grid size={{ xs: 12, md: reports ? 6 : 12 }}>
          <Panel title={t('dashboard.workQueue')}><Queue /></Panel>
        </Grid>
        <Grid size={{ xs: 12 }}>
          <Panel title={t('dashboard.integrity')} action={<Button size="small" component={NextLink} href="/audit/integrity">{t('dashboard.drill')}</Button>}>
            <GapNotice gap="G2">{t('dashboard.integrityGap')}</GapNotice>
          </Panel>
        </Grid>
      </Grid>
    </>
  );
}
