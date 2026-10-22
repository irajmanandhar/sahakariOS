'use client';

import CloudOffOutlined from '@mui/icons-material/CloudOffOutlined';
import ConstructionOutlined from '@mui/icons-material/ConstructionOutlined';
import InboxOutlined from '@mui/icons-material/InboxOutlined';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import { type T, useT } from '@/i18n';
import { ApiError } from '@/lib/api';

/** User-facing text for a failed request, in the current language. API messages are shown as sent. */
export function describeError(t: T, error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) return t('errors.tooMany', { seconds: error.retryAfter ?? 60 });
    return error.message;
  }
  // fetch() rejects with a TypeError when the server cannot be reached.
  if (error instanceof Error && !(error instanceof TypeError)) return error.message;
  return t('errors.network');
}

export function isNetworkError(error: unknown): boolean {
  return error instanceof TypeError;
}

/** A failed load: what happened and a retry. */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const t = useT();
  return (
    <Alert
      severity="error"
      icon={isNetworkError(error) ? <CloudOffOutlined /> : undefined}
      action={onRetry && <Button color="inherit" size="small" onClick={onRetry}>{t('common.retry')}</Button>}
      sx={{ my: 2 }}
    >
      {describeError(t, error)}
    </Alert>
  );
}

/** Inline error for a form action (submit). */
export function FormError({ error }: { error: unknown }) {
  const t = useT();
  if (error === undefined) return null;
  return <Alert severity="error" sx={{ mb: 2 }} role="alert">{describeError(t, error)}</Alert>;
}

export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <Box sx={{ textAlign: 'center', py: 5, px: 2, color: 'text.secondary' }}>
      <InboxOutlined sx={{ fontSize: 36, mb: 1, opacity: 0.6 }} aria-hidden />
      <Typography variant="subtitle1" color="text.primary">{title}</Typography>
      {children && <Typography variant="body2" sx={{ mt: 0.5, maxWidth: 520, mx: 'auto' }}>{children}</Typography>}
      {action && <Box sx={{ mt: 2 }}>{action}</Box>}
    </Box>
  );
}

export function LoadingBlock({ rows = 5 }: { rows?: number }) {
  const t = useT();
  return (
    <Box role="status" aria-label={t('common.loading')} sx={{ py: 1 }}>
      {Array.from({ length: rows }, (_, i) => <Skeleton key={i} height={34} sx={{ transform: 'none', mb: 0.75 }} />)}
    </Box>
  );
}

/**
 * Marks UI built against a missing endpoint. `gap` is the id in docs/frontend-gaps.md.
 * Never invents data: it explains what is missing.
 */
export function GapNotice({ gap, children }: { gap: string; children?: ReactNode }) {
  const t = useT();
  return (
    <Alert severity="info" variant="outlined" icon={<ConstructionOutlined />} sx={{ my: 2 }}>
      <AlertTitle>{t('common.pendingApi')}</AlertTitle>
      {children ?? t('common.pendingApiBody', { gap })}
      {children && <Typography variant="caption" component="div" sx={{ mt: 0.5 }}>docs/frontend-gaps.md · {gap}</Typography>}
    </Alert>
  );
}
