'use client';

import Alert from '@mui/material/Alert';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import { useRouter } from 'next/navigation';
import { type FormEvent, useState } from 'react';
import AuthCard from '@/components/AuthCard';
import { SubmitButton } from '@/components/fields';
import { describeError } from '@/components/States';
import { useT } from '@/i18n';
import { api } from '@/lib/api';

/**
 * SaaS platform administrators (separate guard and session from cooperative staff; signing in
 * here signs the browser out of a staff session).
 */
export default function PlatformLoginPage() {
  const t = useT();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setSubmitting(true);
    setError(null);
    try {
      await api('/platform/auth/login', { method: 'POST', body: { email: form.get('email'), password: form.get('password') } });
      router.replace('/platform');
    } catch (err) {
      setError(describeError(t, err));
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title={t('platform.signInTitle')} subtitle={t('platform.signInHelp')}>
      <Stack component="form" method="post" spacing={2} onSubmit={onSubmit} noValidate>
        {error && <Alert severity="error" role="alert">{error}</Alert>}
        <TextField label={t('auth.email')} name="email" type="email" autoComplete="username" required fullWidth size="medium" />
        <TextField label={t('auth.password')} name="password" type="password" autoComplete="current-password" required fullWidth size="medium" />
        <SubmitButton size="large" submitting={submitting}>{t('auth.signIn')}</SubmitButton>
      </Stack>
    </AuthCard>
  );
}
