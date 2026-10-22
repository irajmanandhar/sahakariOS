'use client';

import ArrowForward from '@mui/icons-material/ArrowForward';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import AuthCard from '@/components/AuthCard';
import { describeError, LoadingBlock } from '@/components/States';
import { useT } from '@/i18n';
import { api, type Membership, redirectForAuthError } from '@/lib/api';

export default function SelectCooperativePage() {
  const t = useT();
  const router = useRouter();
  const [memberships, setMemberships] = useState<Membership[] | null>(null);
  const [error, setError] = useState<unknown>();
  const [choosing, setChoosing] = useState<number | null>(null);

  useEffect(() => {
    api<{ data: Membership[] }>('/auth/cooperatives')
      .then(({ data }) => setMemberships(data))
      .catch((err) => redirectForAuthError(err, router.replace) || setError(err));
  }, [router]);

  async function choose(cooperativeId: number) {
    setError(undefined);
    setChoosing(cooperativeId);
    try {
      await api('/auth/cooperative', { method: 'PUT', body: { cooperative_id: cooperativeId } });
      router.replace('/');
    } catch (err) {
      if (!redirectForAuthError(err, router.replace)) setError(err);
      setChoosing(null);
    }
  }

  async function signOut() {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined);
    router.replace('/login');
  }

  return (
    <AuthCard title={t('auth.chooseTitle')} subtitle={t('auth.chooseHelp')} wide>
      {error !== undefined && <Alert severity="error" role="alert" sx={{ mb: 2 }}>{describeError(t, error)}</Alert>}
      {memberships === null && error === undefined && <LoadingBlock rows={2} />}
      {memberships?.length === 0 && <Alert severity="info">{t('auth.noCooperatives')}</Alert>}
      <List disablePadding sx={{ display: 'grid', gap: 1 }}>
        {memberships?.map((m) => (
          <li key={m.cooperative.id}><ListItemButton disabled={choosing !== null} onClick={() => choose(m.cooperative.id)} sx={{ border: 1, borderColor: 'divider', borderRadius: 1.5 }}>
            <ListItemText primary={m.cooperative.name} secondary={`${m.cooperative.code} · ${t('shell.branch', { name: m.branch.name })}`} />
            {m.cooperative.status === 'licence_suspended' && <Chip color="warning" variant="outlined" label={t('auth.suspended')} sx={{ mr: 1 }} />}
            <ArrowForward fontSize="small" color="action" />
          </ListItemButton></li>
        ))}
      </List>
      <Button onClick={signOut} sx={{ mt: 2 }}>{t('shell.signOut')}</Button>
    </AuthCard>
  );
}
