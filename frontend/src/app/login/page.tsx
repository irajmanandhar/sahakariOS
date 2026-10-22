'use client';

import Alert from '@mui/material/Alert';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import NextLink from 'next/link';
import { useRouter } from 'next/navigation';
import { type FormEvent, useState, useSyncExternalStore } from 'react';
import AuthCard from '@/components/AuthCard';
import { SubmitButton } from '@/components/fields';
import { describeError } from '@/components/States';
import { useT } from '@/i18n';
import { api, destinationAfterLogin } from '@/lib/api';

const noop = () => () => {};

export default function LoginPage() {
  const t = useT();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Read without useSearchParams so the page stays fully static.
  const reason = useSyncExternalStore(noop, () => new URLSearchParams(window.location.search).get('reason'), () => null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setSubmitting(true);
    setError(null);

    try {
      await api('/auth/login', {
        method: 'POST',
        body: { email: form.get('email'), password: form.get('password') },
      });
      router.replace(await destinationAfterLogin());
    } catch (err) {
      setError(describeError(t, err));
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title={t('auth.signInTitle')} subtitle={t('auth.signInHelp')}>
      <Stack component="form" method="post" spacing={2} onSubmit={onSubmit} noValidate>
        {reason === 'idle' && !error && <Alert severity="info">{t('shell.signedOutIdle')}</Alert>}
        {error && <Alert severity="error" role="alert">{error}</Alert>}
        <TextField label={t('auth.email')} name="email" type="email" autoComplete="username" required fullWidth size="medium" />
        <TextField label={t('auth.password')} name="password" type="password" autoComplete="current-password" required fullWidth size="medium" />
        <SubmitButton size="large" submitting={submitting}>{submitting ? t('auth.signingIn') : t('auth.signIn')}</SubmitButton>
        <Link component={NextLink} href="/forgot-password" variant="body2">{t('auth.forgot')}</Link>
      </Stack>
    </AuthCard>
  );
}
