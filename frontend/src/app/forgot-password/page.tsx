'use client';

import Alert from '@mui/material/Alert';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import NextLink from 'next/link';
import { type FormEvent, useState } from 'react';
import AuthCard from '@/components/AuthCard';
import { SubmitButton } from '@/components/fields';
import { describeError } from '@/components/States';
import { useT } from '@/i18n';
import { api } from '@/lib/api';

/** Asks for a reset link. The API answers the same whether or not the address exists. */
export default function ForgotPasswordPage() {
  const t = useT();
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const email = new FormData(event.currentTarget).get('email');
    setSubmitting(true);
    setError(null);
    try {
      const { message } = await api<{ message: string }>('/auth/forgot-password', { method: 'POST', body: { email } });
      setSent(message);
    } catch (err) {
      setError(describeError(t, err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard title={t('auth.forgotTitle')} subtitle={t('auth.forgotHelp')}>
      <Stack component="form" method="post" spacing={2} onSubmit={onSubmit} noValidate>
        {sent && <Alert severity="success" role="status">{sent}</Alert>}
        {error && <Alert severity="error" role="alert">{error}</Alert>}
        <TextField label={t('auth.email')} name="email" type="email" autoComplete="username" required fullWidth size="medium" />
        <SubmitButton size="large" submitting={submitting}>{t('auth.sendLink')}</SubmitButton>
        <Link component={NextLink} href="/login" variant="body2">{t('auth.backToSignIn')}</Link>
      </Stack>
    </AuthCard>
  );
}
