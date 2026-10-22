'use client';

import Box from '@mui/material/Box';
import CircularProgress from '@mui/material/CircularProgress';
import Typography from '@mui/material/Typography';
import { type ReactNode, Suspense } from 'react';
import AppShell from '@/components/shell/AppShell';
import { ErrorState } from '@/components/States';
import { useT } from '@/i18n';
import { SessionProvider } from '@/lib/session';

function Splash({ error, retry }: { error?: unknown; retry: () => void }) {
  const t = useT();
  return (
    <Box component="main" sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', p: 2 }}>
      {error === undefined ? (
        <Box role="status" sx={{ display: 'flex', gap: 2, alignItems: 'center', color: 'text.secondary' }}>
          <CircularProgress size={20} />
          <Typography>{t('common.loading')}</Typography>
        </Box>
      ) : (
        <Box sx={{ maxWidth: 480, width: '100%' }}>
          <Typography variant="h1" gutterBottom>{t('errors.sessionLoadFailed')}</Typography>
          <ErrorState error={error} onRetry={retry} />
        </Box>
      )}
    </Box>
  );
}

export default function StaffFrame({ children }: { children: ReactNode }) {
  return (
    <SessionProvider fallback={(state) => <Splash {...state} />}>
      <Suspense fallback={<Splash retry={() => location.reload()} />}>
        <AppShell>{children}</AppShell>
      </Suspense>
    </SessionProvider>
  );
}
