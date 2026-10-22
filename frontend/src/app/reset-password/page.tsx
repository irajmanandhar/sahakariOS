'use client';

import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import NextLink from 'next/link';
import { type FormEvent, useEffect, useState } from 'react';
import AuthCard from '@/components/AuthCard';
import { SubmitButton } from '@/components/fields';
import { describeError } from '@/components/States';
import { useT } from '@/i18n';
import { ApiError, api } from '@/lib/api';

/** PROPOSED minimum length, same as the API (Password::min(12) in AppServiceProvider). The API decides. */
const MIN_LENGTH = 12;

/**
 * Target of the e-mailed reset link (/reset-password?token=…&email=…). The token is read once and
 * removed from the address bar so it does not stay in history or leak in a Referer.
 */
export default function ResetPasswordPage() {
  const t = useT();
  const [link, setLink] = useState<{ token: string; email: string } | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [done, setDone] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const token = params.get('token');
    const email = params.get('email');
    // eslint-disable-next-line react-hooks/set-state-in-effect -- read the one-time link from the URL after hydration
    setLink(token && email ? { token, email } : null);
    if (token) window.history.replaceState(null, '', '/reset-password');
  }, []);

  const tooShort = password !== '' && password.length < MIN_LENGTH;
  const mismatch = confirmation !== '' && confirmation !== password;

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!link || tooShort || mismatch) return;
    setSubmitting(true);
    setError(null);
    setFieldErrors({});
    try {
      const { message } = await api<{ message: string }>('/auth/reset-password', { method: 'POST', body: { ...link, password, password_confirmation: confirmation } });
      setDone(message);
    } catch (err) {
      setError(describeError(t, err));
      if (err instanceof ApiError) setFieldErrors(err.fieldErrors);
    } finally {
      setSubmitting(false);
    }
  }

  if (done) {
    return (
      <AuthCard title={t('auth.resetTitle')}>
        <Alert severity="success" role="status" sx={{ mb: 2 }}>{done}</Alert>
        <Button variant="contained" component={NextLink} href="/login">{t('auth.signIn')}</Button>
      </AuthCard>
    );
  }

  return (
    <AuthCard title={t('auth.resetTitle')} subtitle={link ? t('auth.resetFor', { email: link.email }) : undefined}>
      {link === null ? (
        <Stack spacing={2}>
          <Alert severity="error">{t('auth.resetInvalid')}</Alert>
          <Link component={NextLink} href="/forgot-password">{t('auth.requestNew')}</Link>
        </Stack>
      ) : (
        <Stack component="form" method="post" spacing={2} onSubmit={onSubmit} noValidate>
          {error && <Alert severity="error" role="alert">{error}</Alert>}
          <TextField
            label={t('auth.newPassword')} type="password" autoComplete="new-password" required fullWidth size="medium"
            value={password} onChange={(e) => setPassword(e.target.value)}
            error={tooShort || !!fieldErrors.password} helperText={fieldErrors.password?.[0] ?? t('auth.minLength', { min: MIN_LENGTH })}
          />
          <TextField
            label={t('auth.confirmPassword')} type="password" autoComplete="new-password" required fullWidth size="medium"
            value={confirmation} onChange={(e) => setConfirmation(e.target.value)}
            error={mismatch} helperText={mismatch ? t('auth.mismatch') : ' '}
          />
          <SubmitButton size="large" submitting={submitting} disabled={!link || !password || tooShort || mismatch || !confirmation}>{t('auth.setPassword')}</SubmitButton>
        </Stack>
      )}
    </AuthCard>
  );
}
