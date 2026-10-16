'use client';

import Alert from '@mui/material/Alert';
import AppBar from '@mui/material/AppBar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Container from '@mui/material/Container';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemText from '@mui/material/ListItemText';
import Toolbar from '@mui/material/Toolbar';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { api, type Branch, errorMessage, type Membership, redirectForAuthError, type User } from '@/lib/api';

type Session = { user: User; membership: Membership; branches: Branch[] };

/** Staff home. Laravel decides access; this page only follows its answers. */
export default function HomePage() {
  const router = useRouter();
  const [session, setSession] = useState<Session | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      const { data: user } = await api<{ data: User }>('/auth/me');
      const { data: membership } = await api<{ data: Membership | null }>('/auth/cooperative');
      if (membership === null) {
        router.replace('/select-cooperative');
        return;
      }
      const { data: branches } = await api<{ data: Branch[] }>('/branches');
      setSession({ user, membership, branches });
    })().catch((err) => redirectForAuthError(err, router.replace) || setError(errorMessage(err)));
  }, [router]);

  async function signOut() {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined);
    router.replace('/login');
  }

  if (error) {
    return (
      <Container sx={{ py: 6 }}>
        <Alert severity="error">{error}</Alert>
      </Container>
    );
  }

  if (!session) {
    return (
      <Container sx={{ py: 6 }}>
        <Typography>Loading…</Typography>
      </Container>
    );
  }

  return (
    <>
      <AppBar position="static">
        <Toolbar sx={{ gap: 2 }}>
          <Typography variant="h6" component="h1" sx={{ flexGrow: 1 }}>
            {session.membership.cooperative.name}
          </Typography>
          <Typography variant="body2">{session.user.name}</Typography>
          <Button color="inherit" onClick={() => router.push('/select-cooperative')}>
            Switch cooperative
          </Button>
          <Button color="inherit" onClick={signOut}>
            Sign out
          </Button>
        </Toolbar>
      </AppBar>
      <Container sx={{ py: 4 }}>
        {session.membership.cooperative.status === 'licence_suspended' && (
          <Alert severity="warning" sx={{ mb: 2 }}>
            This cooperative&apos;s operating licence is suspended.
          </Alert>
        )}
        <Typography variant="subtitle1">Your branch: {session.membership.branch.name}</Typography>
        <Box sx={{ mt: 3 }}>
          <Typography variant="h6" component="h2">
            Branches
          </Typography>
          <List dense>
            {session.branches.map((b) => (
              <ListItem key={b.id}>
                <ListItemText
                  primary={`${b.name}${b.is_head_office ? ' (head office)' : ''}`}
                  secondary={`${b.code} · ${b.status}`}
                />
              </ListItem>
            ))}
          </List>
        </Box>
      </Container>
    </>
  );
}
