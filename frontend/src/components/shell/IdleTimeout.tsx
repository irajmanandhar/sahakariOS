'use client';

import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { useT } from '@/i18n';
import { api, apiActivity } from '@/lib/api';

/**
 * The server ends a session after this many idle minutes (Laravel SESSION_LIFETIME, default 120).
 * The API does not report it (gap G9), so the build sets it: NEXT_PUBLIC_SESSION_IDLE_MINUTES.
 */
const IDLE_MS = Number(process.env.NEXT_PUBLIC_SESSION_IDLE_MINUTES || 120) * 60_000;
const WARN_MS = 2 * 60_000;

/** Warns two minutes before the session expires; "Stay signed in" makes a request, which renews it. */
export default function IdleTimeout() {
  const t = useT();
  const router = useRouter();
  const last = useRef(0);
  const [left, setLeft] = useState<number | null>(null);

  useEffect(() => {
    last.current = Date.now();
    const touch = () => { last.current = Date.now(); };
    apiActivity?.addEventListener('response', touch);
    const tick = setInterval(() => {
      const remaining = IDLE_MS - (Date.now() - last.current);
      if (remaining <= 0) {
        clearInterval(tick);
        api('/auth/logout', { method: 'POST' }).catch(() => undefined).finally(() => router.replace('/login?reason=idle'));
      } else {
        setLeft(remaining <= WARN_MS ? Math.ceil(remaining / 1000) : null);
      }
    }, 1000);
    return () => {
      clearInterval(tick);
      apiActivity?.removeEventListener('response', touch);
    };
  }, [router]);

  return (
    <Dialog open={left !== null} aria-labelledby="idle-title" aria-describedby="idle-body">
      <DialogTitle id="idle-title">{t('shell.idleTitle')}</DialogTitle>
      <DialogContent>
        <DialogContentText id="idle-body">{t('shell.idleBody', { seconds: left ?? 0 })}</DialogContentText>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => api('/auth/logout', { method: 'POST' }).catch(() => undefined).finally(() => router.replace('/login'))}>{t('shell.idleSignOut')}</Button>
        <Button variant="contained" autoFocus onClick={() => api('/auth/me').catch(() => undefined)}>{t('shell.idleStay')}</Button>
      </DialogActions>
    </Dialog>
  );
}
