'use client';

import Button from '@mui/material/Button';
import NextLink from 'next/link';
import AuthCard from '@/components/AuthCard';
import { useT } from '@/i18n';

export default function NotFound() {
  const t = useT();
  return (
    <AuthCard title={t('errors.pageNotFound')} subtitle={t('errors.pageNotFoundHelp')}>
      <Button variant="contained" component={NextLink} href="/">{t('nav.dashboard')}</Button>
    </AuthCard>
  );
}
