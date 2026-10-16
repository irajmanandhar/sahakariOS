'use client';

import Alert from '@mui/material/Alert';
import Container from '@mui/material/Container';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { api, errorMessage, type Membership, redirectForAuthError } from '@/lib/api';

export default function SelectCooperativePage() {
  const router = useRouter();
  const [memberships, setMemberships] = useState<Membership[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Membership[] }>('/auth/cooperatives')
      .then(({ data }) => setMemberships(data))
      .catch((err) => redirectForAuthError(err, router.replace) || setError(errorMessage(err)));
  }, [router]);

  async function choose(cooperativeId: number) {
    setError(null);
    try {
      await api('/auth/cooperative', { method: 'PUT', body: { cooperative_id: cooperativeId } });
      router.replace('/');
    } catch (err) {
      if (!redirectForAuthError(err, router.replace)) setError(errorMessage(err));
    }
  }

  return (
    <Container maxWidth="sm" sx={{ py: 8 }}>
      <Paper sx={{ p: 4 }}>
        <Typography variant="h5" component="h1" gutterBottom>
          Choose a cooperative
        </Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {memberships === null && !error && <Typography>Loading…</Typography>}
        {memberships?.length === 0 && <Alert severity="info">You have no active cooperative access.</Alert>}
        <List>
          {memberships?.map((m) => (
            <ListItemButton key={m.cooperative.id} onClick={() => choose(m.cooperative.id)}>
              <ListItemText primary={m.cooperative.name} secondary={`${m.cooperative.code} · ${m.branch.name}`} />
            </ListItemButton>
          ))}
        </List>
      </Paper>
    </Container>
  );
}
