'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Container from '@mui/material/Container';
import Grid from '@mui/material/Grid';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemText from '@mui/material/ListItemText';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useParams, useRouter } from 'next/navigation';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { MemberStatusChip, RiskChip } from '@/components/StatusChip';
import { api, currentPermissions, errorMessage, type Member, redirectForAuthError } from '@/lib/api';

const DOCUMENT_TYPES: [string, string][] = [
  ['photo', 'Photo'], ['citizenship', 'Citizenship certificate'], ['national_id', 'National ID'], ['voter_id', 'Voter ID'],
  ['passport', 'Passport'], ['driving_licence', 'Driving licence'], ['pan', 'PAN'], ['staff_id', 'Staff ID'],
  ['thumbprint_right', 'Right thumbprint'], ['thumbprint_left', 'Left thumbprint'], ['signature', 'Signature'],
  ['kym_form', 'Signed KYM form'], ['other', 'Other'],
];
const label = (type: string) => DOCUMENT_TYPES.find(([t]) => t === type)?.[1] ?? type;

export default function MemberDetail() {
  const router = useRouter();
  const { id } = useParams<{ id: string }>();
  const [member, setMember] = useState<Member | null>(null);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [kym, setKym] = useState<Record<string, unknown> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [docType, setDocType] = useState('photo');
  const [risk, setRisk] = useState({ level: 'low', reason: '' });
  const [rejectReason, setRejectReason] = useState('');

  const fail = useCallback((err: unknown) => redirectForAuthError(err, router.replace) || setError(errorMessage(err)), [router]);
  const load = useCallback(() => {
    Promise.all([api<{ data: Member }>(`/members/${id}`), currentPermissions()])
      .then(([m, p]) => {
        setMember(m.data);
        setPermissions(p);
      })
      .catch(fail);
  }, [id, fail]);

  useEffect(load, [load]);

  const can = (p: string) => permissions.includes(p);

  async function act(action: () => Promise<unknown>, done: string) {
    setError(null);
    setNotice(null);
    try {
      await action();
      setNotice(done);
      load();
    } catch (err) {
      fail(err);
    }
  }

  async function upload(file: File) {
    const form = new FormData();
    form.append('type', docType);
    form.append('file', file);
    await act(() => api(`/members/${id}/documents`, { form }), `${label(docType)} uploaded.`);
  }

  if (!member) {
    return <Container sx={{ py: 4 }}>{error ? <Alert severity="error">{error}</Alert> : <Typography>Loading…</Typography>}</Container>;
  }

  const pending = member.status === 'applied';
  const box = (title: string, children: ReactNode) => (
    <Paper sx={{ p: 3, mb: 2 }}>
      <Typography variant="h6" component="h2" gutterBottom>
        {title}
      </Typography>
      {children}
    </Paper>
  );

  return (
    <Container maxWidth="md" sx={{ py: 4 }}>
      <Button onClick={() => router.push('/members')}>← Members</Button>
      <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap', my: 2 }}>
        <Typography variant="h5" component="h1" sx={{ mr: 1 }}>
          {member.full_name}
          {member.member_number && ` · No. ${member.member_number}`}
        </Typography>
        <MemberStatusChip status={member.status} />
        <RiskChip level={member.risk_level} />
        {member.is_pep && <Alert severity="warning" sx={{ py: 0 }}>PEP</Alert>}
      </Box>
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      {notice && <Alert severity="success" sx={{ mb: 2 }}>{notice}</Alert>}

      {box('Details', (
        <Grid container spacing={1}>
          {[
            ['Name (Devanagari)', member.full_name_ne ?? '—'],
            ['Gender', member.gender],
            ['Date of birth', `${member.date_of_birth}${member.date_of_birth_bs ? ` (BS ${member.date_of_birth_bs})` : ''}`],
            ['Phone', member.phone ?? '—'],
            ['Applied on', member.applied_on],
            ['Decision', pending ? (member.days_to_decide! < 0 ? `${-member.days_to_decide!} days overdue` : `due in ${member.days_to_decide} days`) : `${member.status} ${member.decided_at?.slice(0, 10) ?? ''}`],
            ['Reason', member.decision_reason ?? '—'],
            ['Next KYM review', member.next_kyc_review_on ?? '—'],
          ].map(([k, v]) => (
            <Grid size={{ xs: 12, sm: 6 }} key={k}>
              <Typography variant="caption" color="text.secondary">{k}</Typography>
              <Typography>{v}</Typography>
            </Grid>
          ))}
        </Grid>
      ))}

      {box('Documents', <>
        <List dense>
          {member.documents?.length === 0 && <ListItem><ListItemText primary="No documents yet." /></ListItem>}
          {member.documents?.map((d) => (
            <ListItem key={d.id} secondaryAction={can('member.kyc_view') && (
              <Button size="small" href={`/api/members/${member.id}/documents/${d.id}`} target="_blank" rel="noopener">View</Button>
            )}>
              <ListItemText primary={label(d.type)} secondary={`${d.mime_type} · ${Math.round(d.size_bytes / 1024)} KB · ${d.uploaded_at.slice(0, 10)}`} />
            </ListItem>
          ))}
        </List>
        {can('member.apply') && ['applied', 'active'].includes(member.status) && (
          <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
            <TextField select size="small" label="Document type" value={docType} onChange={(e) => setDocType(e.target.value)} sx={{ minWidth: 220 }}>
              {DOCUMENT_TYPES.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
            </TextField>
            <Button variant="outlined" component="label">
              Upload file
              <input
                hidden
                type="file"
                accept="image/jpeg,image/png,application/pdf"
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  e.target.value = ''; // so choosing the same file again (for another document type) still fires
                  if (file) upload(file);
                }}
              />
            </Button>
            <Typography variant="caption">JPEG, PNG or PDF, max 5 MB. Required: photo, both thumbprints, one ID copy.</Typography>
          </Box>
        )}
      </>)}

      {can('member.risk_assess') && ['applied', 'active'].includes(member.status) && box('AML/CFT risk', (
        <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
          <TextField select size="small" label="Risk level" value={risk.level} onChange={(e) => setRisk({ ...risk, level: e.target.value })} sx={{ minWidth: 140 }}>
            {['low', 'medium', 'high'].map((l) => <MenuItem key={l} value={l}>{l}</MenuItem>)}
          </TextField>
          <TextField size="small" label="Reason" value={risk.reason} onChange={(e) => setRisk({ ...risk, reason: e.target.value })} sx={{ flexGrow: 1 }} />
          <Button variant="outlined" onClick={() => act(() => api(`/members/${id}/risk`, { method: 'POST', body: { risk_level: risk.level, reason: risk.reason } }), 'Risk assessed.')}>
            Save risk
          </Button>
        </Box>
      ))}

      {pending && can('member.decide') && box('Decision', (
        <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center' }}>
          <Button variant="contained" color="success" onClick={() => act(() => api(`/members/${id}/approve`, { method: 'POST' }), 'Member approved.')}>
            Approve
          </Button>
          <TextField size="small" label="Rejection reason" value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} sx={{ flexGrow: 1 }} />
          <Button variant="outlined" color="error" onClick={() => act(() => api(`/members/${id}/reject`, { method: 'POST', body: { reason: rejectReason } }), 'Application rejected.')}>
            Reject
          </Button>
        </Box>
      ))}

      {can('member.kyc_view') && box('KYM form (confidential)', kym ? (
        <Box component="pre" sx={{ whiteSpace: 'pre-wrap', fontSize: 13, m: 0 }}>{JSON.stringify(kym, null, 2)}</Box>
      ) : (
        <Button onClick={() => api<{ data: { kym: Record<string, unknown> } | null }>(`/members/${id}/kyc`).then((r) => setKym(r.data?.kym ?? {})).catch(fail)}>
          Show KYM details (this view is audited)
        </Button>
      ))}
    </Container>
  );
}
