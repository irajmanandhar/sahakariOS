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
import { useEffect, useState } from 'react';
import { MemberStatusChip, RiskChip } from '@/components/StatusChip';
import { api, currentPermissions, errorMessage, type Member, redirectForAuthError } from '@/lib/api';

const FILTERS = { '': 'All', applied: 'Pending applications', active: 'Active', rejected: 'Rejected', review: 'KYM review due' } as const;

export default function MembersPage() {
  const router = useRouter();
  const [filter, setFilter] = useState<keyof typeof FILTERS>('');
  const [members, setMembers] = useState<Member[] | null>(null);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const query = filter === 'review' ? '?kyc_review_due=1' : filter ? `?status=${filter}` : '';
    Promise.all([api<{ data: Member[] }>(`/members${query}`), currentPermissions()])
      .then(([m, p]) => {
        setMembers(m.data);
        setPermissions(p);
      })
      .catch((err) => redirectForAuthError(err, router.replace) || setError(errorMessage(err)));
  }, [filter, router]);

  return (
    <Container sx={{ py: 4 }}>
      <Button onClick={() => router.push('/')}>← Home</Button>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, my: 2, flexWrap: 'wrap' }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>
          Members
        </Typography>
        <TextField select size="small" label="Show" value={filter} onChange={(e) => setFilter(e.target.value as keyof typeof FILTERS)} sx={{ minWidth: 200 }}>
          {Object.entries(FILTERS).map(([value, label]) => (
            <MenuItem key={value} value={value}>
              {label}
            </MenuItem>
          ))}
        </TextField>
        {permissions.includes('member.apply') && (
          <Button variant="contained" onClick={() => router.push('/members/new')}>
            New application
          </Button>
        )}
      </Box>
      {error && <Alert severity="error">{error}</Alert>}
      {!members && !error && <Typography>Loading…</Typography>}
      {members && (
        <Paper sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>No.</TableCell>
                <TableCell>Name</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Risk</TableCell>
                <TableCell>Applied</TableCell>
                <TableCell>Decision due</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {members.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6}>No members found.</TableCell>
                </TableRow>
              )}
              {members.map((m) => (
                <TableRow key={m.id} hover sx={{ cursor: 'pointer' }} onClick={() => router.push(`/members/${m.id}`)}>
                  <TableCell>{m.member_number ?? '—'}</TableCell>
                  <TableCell>
                    {m.full_name}
                    {m.full_name_ne && ` (${m.full_name_ne})`}
                  </TableCell>
                  <TableCell>
                    <MemberStatusChip status={m.status} />
                  </TableCell>
                  <TableCell>
                    <RiskChip level={m.risk_level} />
                  </TableCell>
                  <TableCell>{m.applied_on}</TableCell>
                  <TableCell sx={{ color: (m.days_to_decide ?? 1) < 0 ? 'error.main' : undefined }}>
                    {m.days_to_decide === null ? '—' : m.days_to_decide < 0 ? `${-m.days_to_decide} days overdue` : `${m.days_to_decide} days left`}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}
    </Container>
  );
}
