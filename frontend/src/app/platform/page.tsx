'use client';

import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import AuthCard from '@/components/AuthCard';
import { ErrorState, GapNotice, LoadingBlock } from '@/components/States';
import { useT } from '@/i18n';
import { ApiError, api } from '@/lib/api';
import type { User } from '@/lib/types';

/** Minimal platform-admin home: who is signed in and sign out. Cooperative management has no API yet (gap G8). */
export default function PlatformHomePage() {
  const t = useT();
  const router = useRouter();
  const [me, setMe] = useState<User | null>(null);
  const [error, setError] = useState<unknown>();

  useEffect(() => {
    api<{ data: User }>('/platform/auth/me')
      .then(({ data }) => setMe(data))
      .catch((e) => (e instanceof ApiError && e.status === 401 ? router.replace('/platform/login') : setError(e)));
  }, [router]);

  async function signOut() {
    await api('/platform/auth/logout', { method: 'POST' }).catch(() => undefined);
    router.replace('/platform/login');
  }

  return (
    <AuthCard title={t('platform.title')} wide>
      {error !== undefined && <ErrorState error={error} onRetry={() => location.reload()} />}
      {!me && error === undefined && <LoadingBlock rows={2} />}
      {me && (
        <>
          <Typography>{t('platform.signedInAs', { name: me.name, email: me.email })}</Typography>
          <GapNotice gap="G8">{t('platform.gap')}</GapNotice>
          <Box sx={{ display: 'flex', gap: 1 }}>
            <Button variant="outlined" onClick={signOut}>{t('shell.signOut')}</Button>
          </Box>
        </>
      )}
    </AuthCard>
  );
}
