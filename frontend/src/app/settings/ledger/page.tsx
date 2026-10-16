'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Container from '@mui/material/Container';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { api, currentPermissions, errorMessage, redirectForAuthError } from '@/lib/api';

type Mapping = { role: string; description: string; account_type: string; ledger_account_id: number | null };
type Account = { id: number; code: string; name: string; type: string; is_postable: boolean };
type Tags = { tags: { tag: string; description: string; account_type: string }[]; assigned: { code: string; tag: string }[] };

/** Which of the cooperative's ledger accounts plays each role. The chart itself is the accountant's. */
export default function LedgerSettingsPage() {
  const router = useRouter();
  const [mappings, setMappings] = useState<Mapping[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [canEdit, setCanEdit] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [tags, setTags] = useState<Tags | null>(null);
  const [tagForm, setTagForm] = useState<{ code: string; tags: string[] }>({ code: '', tags: [] });

  const fail = useCallback((err: unknown) => redirectForAuthError(err, router.replace) || setError(errorMessage(err)), [router]);
  useEffect(() => {
    Promise.all([api<{ data: Mapping[] }>('/ledger-mappings'), api<{ data: Account[] }>('/ledger-accounts'), currentPermissions(), api<{ data: Tags }>('/ledger-account-tags')])
      .then(([m, a, p, t]) => {
        setMappings(m.data);
        setAccounts(a.data);
        setCanEdit(p.includes('ledger_account.manage'));
        setTags(t.data);
      })
      .catch(fail);
  }, [fail]);

  async function change(role: string, code: string) {
    setError(null);
    setNotice(null);
    try {
      const { data } = await api<{ data: Mapping[] }>('/ledger-mappings', { method: 'PUT', body: { role, account_code: code } });
      setMappings(data);
      setNotice('Saved.');
    } catch (err) {
      fail(err);
    }
  }

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>
        Ledger account roles
      </Typography>
      <Alert severity="info" sx={{ mb: 2 }}>
        SahakariOS ships no chart of accounts. Choose which of your accounts (agreed with your accountant) each role posts to.
      </Alert>
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      {notice && <Alert severity="success" sx={{ mb: 2 }}>{notice}</Alert>}
      <Paper sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Role</TableCell>
              <TableCell>Account</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {mappings.map((m) => (
              <TableRow key={m.role}>
                <TableCell>
                  {m.description}
                  <Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{m.role} · {m.account_type} account</Typography>
                </TableCell>
                <TableCell sx={{ minWidth: 280 }}>
                  <TextField select fullWidth size="small" disabled={!canEdit} label="Account"
                    value={accounts.find((a) => a.id === m.ledger_account_id)?.code ?? ''}
                    onChange={(e) => change(m.role, e.target.value)}>
                    {accounts.filter((a) => a.is_postable && a.type === m.account_type).map((a) => (
                      <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>
                    ))}
                  </TextField>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Paper>

      {tags && (
        <Paper sx={{ p: 3, mt: 3 }}>
          <Typography variant="h6" component="h2" gutterBottom>Report tags</Typography>
          <Typography variant="body2" sx={{ mb: 2 }}>What an account is for the liquidity and capital returns (e.g. bank deposit, government bond).</Typography>
          <Table size="small" sx={{ mb: 2 }}>
            <TableBody>
              {tags.assigned.length === 0 && <TableRow><TableCell>No tags yet.</TableCell></TableRow>}
              {tags.assigned.map((a) => <TableRow key={`${a.code}-${a.tag}`}><TableCell>{a.code}</TableCell><TableCell>{tags.tags.find((t) => t.tag === a.tag)?.description}</TableCell></TableRow>)}
            </TableBody>
          </Table>
          {canEdit && (
            <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
              <TextField select size="small" label="Account" value={tagForm.code} sx={{ minWidth: 240 }}
                onChange={(e) => setTagForm({ code: e.target.value, tags: tags.assigned.filter((a) => a.code === e.target.value).map((a) => a.tag) })}>
                {accounts.filter((a) => a.is_postable && ['asset', 'equity'].includes(a.type)).map((a) => <MenuItem key={a.id} value={a.code}>{a.code} · {a.name}</MenuItem>)}
              </TextField>
              <TextField select size="small" label="Tags" value={tagForm.tags} sx={{ minWidth: 320 }}
                slotProps={{ select: { multiple: true } }}
                onChange={(e) => setTagForm({ ...tagForm, tags: e.target.value as unknown as string[] })}>
                {tags.tags.filter((t) => t.account_type === accounts.find((a) => a.code === tagForm.code)?.type).map((t) => <MenuItem key={t.tag} value={t.tag}>{t.description}</MenuItem>)}
              </TextField>
              <Button variant="outlined" disabled={!tagForm.code} onClick={async () => {
                try {
                  setTags((await api<{ data: Tags }>('/ledger-account-tags', { method: 'PUT', body: { account_code: tagForm.code, tags: tagForm.tags } })).data);
                  setNotice('Tags saved.');
                } catch (e) {
                  fail(e);
                }
              }}>Save tags</Button>
            </Box>
          )}
        </Paper>
      )}
    </Container>
  );
}
