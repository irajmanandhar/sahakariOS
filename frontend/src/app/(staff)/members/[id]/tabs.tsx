'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import CheckCircleOutlined from '@mui/icons-material/CheckCircleOutlined';
import RadioButtonUncheckedOutlined from '@mui/icons-material/RadioButtonUncheckedOutlined';
import UploadFileOutlined from '@mui/icons-material/UploadFileOutlined';
import VisibilityOutlined from '@mui/icons-material/VisibilityOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import NextLink from 'next/link';
import { type ReactNode, useEffect, useState } from 'react';
import BsDate from '@/components/BsDate';
import ConfirmDialog from '@/components/ConfirmDialog';
import DataTable, { type Column } from '@/components/DataTable';
import { KymSummary, SECTIONS } from '@/components/members/kym';
import MoneyText, { useDigits } from '@/components/MoneyText';
import StatusChip from '@/components/StatusChip';
import { ErrorState, GapNotice, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { type MessageKey, useT } from '@/i18n';
import { api, apiBlob } from '@/lib/api';
import { useApi, useSubmit } from '@/lib/hooks';
import { compareMoney, formatMoney, fromPaisa } from '@/lib/money';
import { DOCUMENT_MAX_BYTES, DOCUMENT_TYPES, ID_DOCUMENTS, REQUIRED_DOCUMENTS, SHARE_FACE_VALUE_PAISA, SOURCE_OF_FUNDS_FROM } from '@/lib/rules';
import { useSession } from '@/lib/session';
import type { Kyc, Loan, Member, MemberDocument, SavingsAccount, SavingsProduct, ShareHolding } from '@/lib/types';

const docLabel = (type: string): MessageKey => (DOCUMENT_TYPES.includes(type as (typeof DOCUMENT_TYPES)[number]) ? `members.doc.${type as (typeof DOCUMENT_TYPES)[number]}` : 'members.doc.other');

function Section({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <Paper component="section" aria-label={title} sx={{ p: { xs: 2, sm: 3 }, mb: 2 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 2, flexWrap: 'wrap' }}>
        <Typography variant="h2" sx={{ flexGrow: 1 }}>{title}</Typography>
        {action}
      </Box>
      {children}
    </Paper>
  );
}

export function OverviewTab({ member: m }: { member: Member }) {
  const t = useT();
  const digits = useDigits();
  const fact = (label: string, value: ReactNode) => (
    <Grid size={{ xs: 12, sm: 6, md: 4 }} key={label}>
      <Typography variant="caption" color="text.secondary" component="div">{label}</Typography>
      <Typography component="div">{value ?? '—'}</Typography>
    </Grid>
  );
  return (
    <Section title={t('members.tab.overview')}>
      <Grid container spacing={2}>
        {fact(t('kym.fullNameNe'), m.full_name_ne)}
        {fact(t('kym.gender'), m.gender ? t(m.gender === 'female' ? 'kym.female' : m.gender === 'male' ? 'kym.male' : 'kym.otherGender') : t('common.notRecorded'))}
        {fact(t('kym.dob'), m.date_of_birth ? <BsDate ad={m.date_of_birth} bs={m.date_of_birth_bs} /> : t('common.notRecorded'))}
        {fact(t('kym.phone'), m.phone)}
        {fact(t('members.appliedOn'), <BsDate ad={m.applied_on} />)}
        {fact(t('members.decision'), m.decided_at ? <><StatusChip kind="member" value={m.status} /> <BsDate ad={m.decided_at} /></> : m.days_to_decide !== null ? t('members.decideIn', { days: digits(m.days_to_decide) }) : '—')}
        {fact(t('members.decisionReason'), m.decision_reason)}
        {fact(t('members.nextReview'), <BsDate ad={m.next_kyc_review_on} />)}
      </Grid>
    </Section>
  );
}

/** Image or PDF preview of a private document, fetched through the API (each view is audited). */
function Preview({ memberId, doc, onClose }: { memberId: number; doc: MemberDocument | null; onClose: () => void }) {
  const t = useT();
  const [url, setUrl] = useState<string | null>(null);
  const [error, setError] = useState<unknown>();
  useEffect(() => {
    if (!doc) return;
    let objectUrl: string | null = null;
    apiBlob(`/members/${memberId}/documents/${doc.id}`)
      .then((blob) => { objectUrl = URL.createObjectURL(blob); setUrl(objectUrl); })
      .catch(setError);
    return () => {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      setUrl(null);
      setError(undefined);
    };
  }, [memberId, doc]);

  return (
    <Dialog open={!!doc} onClose={onClose} maxWidth="md" fullWidth aria-labelledby="doc-title">
      <DialogTitle id="doc-title">{doc && t(docLabel(doc.type))}</DialogTitle>
      <DialogContent>
        <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>{t('members.viewAudited')}</Alert>
        {error !== undefined && <ErrorState error={error} />}
        {!url && error === undefined && <LoadingBlock rows={4} />}
        {url && doc?.mime_type === 'application/pdf' && <Box component="iframe" src={url} title={t(docLabel(doc.type))} sx={{ width: '100%', height: '70vh', border: 0 }} />}
        {url && doc?.mime_type.startsWith('image/') && <Box component="img" src={url} alt={t(docLabel(doc.type))} sx={{ maxWidth: '100%', display: 'block', mx: 'auto' }} />}
      </DialogContent>
      <DialogActions><Button onClick={onClose}>{t('common.close')}</Button></DialogActions>
    </Dialog>
  );
}

export function DocumentsTab({ member: m, onChange }: { member: Member; onChange: () => void }) {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const [type, setType] = useState<string>('photo');
  const [file, setFile] = useState<File | null>(null);
  const [localUrl, setLocalUrl] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [preview, setPreview] = useState<MemberDocument | null>(null);
  const docs = m.documents ?? [];
  const has = (types: string[]) => docs.some((d) => types.includes(d.type));
  const checklist: [string, boolean][] = [...REQUIRED_DOCUMENTS.map((r): [string, boolean] => [t(docLabel(r)), has([r])]), [t('members.oneId'), has(ID_DOCUMENTS)]];
  const upload = useSubmit('doc', async () => {
    const form = new FormData();
    form.append('type', type);
    form.append('file', file!);
    return api(`/members/${m.id}/documents`, { form });
  });

  useEffect(() => () => { if (localUrl) URL.revokeObjectURL(localUrl); }, [localUrl]);

  function choose(f: File | undefined) {
    setProblem(null);
    if (!f) return;
    if (!['image/jpeg', 'image/png', 'application/pdf'].includes(f.type)) return setProblem(t('members.fileType'));
    if (f.size > DOCUMENT_MAX_BYTES) return setProblem(t('members.fileSize'));
    setFile(f);
    setLocalUrl(f.type.startsWith('image/') ? URL.createObjectURL(f) : null);
  }

  async function send() {
    if (!file) return;
    if (await upload.submit() !== undefined) {
      toast.success(t('members.uploaded', { type: t(docLabel(type)) }));
      setFile(null);
      setLocalUrl(null);
      onChange();
    }
  }

  const columns: Column<MemberDocument>[] = [
    { key: 'type', header: t('common.type'), mobile: 'title', value: (d) => t(docLabel(d.type)) },
    { key: 'mime_type', header: t('members.format'), value: (d) => (d.mime_type === 'application/pdf' ? 'PDF' : d.mime_type.replace('image/', '').toUpperCase()) },
    { key: 'size_bytes', header: t('members.size'), value: (d) => `${Math.max(1, Math.round(d.size_bytes / 1024))} KB`, align: 'right' },
    { key: 'uploaded_at', header: t('members.uploadedAt'), render: (d) => <BsDate ad={d.uploaded_at} time /> },
    ...(can('member.kyc_view') ? [{ key: 'view', header: '', sortable: false, render: (d: MemberDocument) => <Button size="small" startIcon={<VisibilityOutlined />} onClick={(e) => { e.stopPropagation(); setPreview(d); }}>{t('common.view')}</Button> }] : []),
  ];

  return (
    <>
      {m.status === 'applied' && (
        <Section title={t('members.requiredDocs')}>
          <Box component="ul" sx={{ listStyle: 'none', m: 0, p: 0, display: 'flex', gap: 1, flexWrap: 'wrap' }}>
            {checklist.map(([label, ok]) => (
              <li key={label}><Chip variant={ok ? 'filled' : 'outlined'} color={ok ? 'success' : 'default'} icon={ok ? <CheckCircleOutlined /> : <RadioButtonUncheckedOutlined />} label={`${label}: ${ok ? t('members.provided') : t('members.missing')}`} /></li>
            ))}
          </Box>
        </Section>
      )}
      <DataTable label={t('members.tab.documents')} columns={columns} rows={docs} rowKey={(d) => d.id} emptyTitle={t('members.noDocs')} />
      {can('member.apply') && ['applied', 'active'].includes(m.status) && (
        <Section title={t('members.uploadTitle')}>
          {problem && <Alert severity="error" sx={{ mb: 2 }}>{problem}</Alert>}
          {upload.error !== undefined && <ErrorState error={upload.error} />}
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center' }}>
            <TextField select label={t('members.docType')} value={type} onChange={(e) => setType(e.target.value)} sx={{ minWidth: 240 }}>
              {DOCUMENT_TYPES.map((d) => <MenuItem key={d} value={d}>{t(docLabel(d))}</MenuItem>)}
            </TextField>
            <Button variant="outlined" component="label" startIcon={<UploadFileOutlined />}>
              {file ? file.name : t('members.chooseFile')}
              <input hidden type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => { choose(e.target.files?.[0]); e.target.value = ''; }} />
            </Button>
            <Button variant="contained" disabled={!file || upload.submitting} onClick={send}>{t('members.upload')}</Button>
          </Box>
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>{t('members.fileRules')}</Typography>
          {localUrl && <Box component="img" src={localUrl} alt={t('members.selectedPreview')} sx={{ mt: 2, maxHeight: 220, maxWidth: '100%', border: 1, borderColor: 'divider', borderRadius: 1 }} />}
        </Section>
      )}
      <Preview memberId={m.id} doc={preview} onClose={() => setPreview(null)} />
    </>
  );
}

export function SharesTab({ member: m }: { member: Member }) {
  const t = useT();
  const toast = useToast();
  const digits = useDigits();
  const { can } = useSession();
  const shares = useApi<ShareHolding>(`/members/${m.id}/shares`);
  const [units, setUnits] = useState('');
  const [source, setSource] = useState('');
  const [confirm, setConfirm] = useState(false);
  const count = /^\d+$/.test(units) ? BigInt(units) : 0n;
  const amount = fromPaisa(count * SHARE_FACE_VALUE_PAISA);
  const needsSource = compareMoney(amount, SOURCE_OF_FUNDS_FROM) >= 0;
  const buy = useSubmit('share', async ({ idempotencyKey }) => api(`/members/${m.id}/shares`, { method: 'POST', body: { units: Number(units), source_of_funds: source || null }, idempotencyKey }));

  const columns: Column<ShareHolding['transactions'][number]>[] = [
    { key: 'date', header: t('common.date'), mobile: 'title', render: (r) => <BsDate ad={r.date} /> },
    { key: 'type', header: t('common.type'), value: (r) => (r.type === 'purchase' ? t('members.purchase') : r.type === 'refund' ? t('members.refund') : r.type) },
    { key: 'units', header: t('members.units'), align: 'right' },
    { key: 'amount', header: t('common.amountRs'), money: true },
  ];

  return (
    <>
      {shares.error !== undefined && <ErrorState error={shares.error} onRetry={shares.reload} />}
      <Section title={t('members.holding')}>
        {shares.data ? (
          <Typography sx={{ fontSize: '1.25rem', fontWeight: 650 }}>{t('members.holdingText', { units: digits(shares.data.units) })} · <MoneyText value={shares.data.amount} prefix /></Typography>
        ) : <LoadingBlock rows={1} />}
        <Typography variant="caption" color="text.secondary">{t('members.faceValue')}</Typography>
      </Section>
      <DataTable label={t('nav.shares')} columns={columns} rows={shares.data?.transactions ?? []} rowKey={(r) => r.id} loading={shares.loading} emptyTitle={t('members.noShares')} csvName={`shares-${m.member_number ?? m.id}`} />
      {can('share.purchase') && m.status === 'active' && (
        <Section title={t('members.buyShares')}>
          <Box component="form" method="post" noValidate onSubmit={(e) => { e.preventDefault(); if (count > 0n) setConfirm(true); }} sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'flex-start' }}>
            <TextField label={t('members.unitsToBuy')} value={units} onChange={(e) => setUnits(e.target.value.replace(/\D/g, ''))} slotProps={{ htmlInput: { inputMode: 'numeric' } }} helperText={count > 0n ? `= ${t('common.rs')} ${digits(formatMoney(amount))}` : ' '} />
            {needsSource && <TextField required label={t('members.sourceOfFunds')} value={source} onChange={(e) => setSource(e.target.value)} sx={{ flexGrow: 1, minWidth: 260 }} helperText={t('members.sourceRequired')} />}
            <Button type="submit" variant="contained" disabled={count === 0n || (needsSource && !source.trim())} sx={{ mt: 0.25 }}>{t('members.reviewPurchase')}</Button>
          </Box>
        </Section>
      )}
      <ConfirmDialog
        open={confirm}
        title={t('members.confirmPurchase')}
        confirmLabel={t('members.buy')}
        onClose={() => setConfirm(false)}
        onConfirm={async () => {
          await buy.attempt();
          toast.success(t('members.sharesBought'));
          setUnits('');
          setSource('');
          shares.reload();
        }}
      >
        {t('members.purchaseBody', { units: digits(units), name: m.full_name })} <MoneyText value={amount} prefix strong />
      </ConfirmDialog>
    </>
  );
}

export function SavingsTab({ member: m }: { member: Member }) {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const accounts = useApi<SavingsAccount[]>(`/members/${m.id}/savings-accounts`);
  const products = useApi<SavingsProduct[]>(can('savings.open') && m.status === 'active' ? '/savings-products' : null);
  const [product, setProduct] = useState('');
  const [confirm, setConfirm] = useState(false);
  const chosen = products.data?.find((p) => String(p.id) === product);

  const columns: Column<SavingsAccount>[] = [
    { key: 'account_number', header: t('savings.accountNo'), mobile: 'title' },
    { key: 'product', header: t('savings.product'), value: (a) => `${a.product.code} · ${a.product.name}` },
    { key: 'status', header: t('common.status'), render: (a) => <StatusChip kind="account" value={a.status} /> },
    { key: 'opened_on', header: t('savings.openedOn'), render: (a) => <BsDate ad={a.opened_on} /> },
    { key: 'maturity_date', header: t('savings.maturity'), render: (a) => <BsDate ad={a.maturity_date} />, hidden: true },
    { key: 'hold_amount', header: t('savings.hold'), money: true },
    { key: 'balance', header: t('savings.balance'), money: true },
  ];

  return (
    <>
      <DataTable label={t('nav.savings')} columns={columns} rows={accounts.data ?? []} rowKey={(a) => a.id} rowHref={(a) => `/savings/accounts/${a.id}`} loading={accounts.loading} error={accounts.error} onRetry={accounts.reload} emptyTitle={t('savings.noAccounts')} />
      {can('savings.open') && m.status === 'active' && (
        <Section title={t('savings.openAccount')}>
          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
            <TextField select label={t('savings.product')} value={product} onChange={(e) => setProduct(e.target.value)} sx={{ minWidth: 280 }}>
              {(products.data ?? []).filter((p) => p.status === 'active').map((p) => <MenuItem key={p.id} value={String(p.id)}>{p.code} · {p.name} ({p.interest_rate}%)</MenuItem>)}
            </TextField>
            <Button variant="contained" startIcon={<AddOutlined />} disabled={!product} onClick={() => setConfirm(true)}>{t('savings.openAccount')}</Button>
          </Box>
        </Section>
      )}
      <ConfirmDialog open={confirm} title={t('savings.openTitle')} confirmLabel={t('savings.openAccount')} onClose={() => setConfirm(false)}
        onConfirm={async () => {
          await api(`/members/${m.id}/savings-accounts`, { method: 'POST', body: { savings_product_id: Number(product) } });
          toast.success(t('savings.opened'));
          setProduct('');
          accounts.reload();
        }}>
        {t('savings.openBody', { product: chosen ? `${chosen.code} · ${chosen.name}` : '', name: m.full_name })}
      </ConfirmDialog>
    </>
  );
}

export function LoansTab({ member: m }: { member: Member }) {
  const t = useT();
  const { can } = useSession();
  const loans = useApi<Loan[]>(`/members/${m.id}/loans`);
  const columns: Column<Loan>[] = [
    { key: 'loan_number', header: t('loans.number'), mobile: 'title' },
    { key: 'product', header: t('loans.product'), value: (l) => `${l.product.code} · ${l.product.name}` },
    { key: 'status', header: t('common.status'), render: (l) => <StatusChip kind="loan" value={l.status} /> },
    { key: 'classification', header: t('loans.classification'), render: (l) => (l.status === 'disbursed' ? <StatusChip kind="classification" value={l.classification} /> : '—') },
    { key: 'principal', header: t('loans.principal'), money: true },
    { key: 'outstanding_principal', header: t('loans.outstanding'), money: true },
  ];
  return (
    <>
      {can('loan.apply') && m.status === 'active' && (
        <Box sx={{ mb: 2 }}><Button variant="contained" startIcon={<AddOutlined />} component={NextLink} href={`/loans/new?member=${m.id}`}>{t('loans.newApplication')}</Button></Box>
      )}
      <DataTable label={t('nav.loans')} columns={columns} rows={loans.data ?? []} rowKey={(l) => l.id} rowHref={(l) => `/loans/${l.id}`} loading={loans.loading} error={loans.error} onRetry={loans.reload} emptyTitle={t('loans.none')} />
    </>
  );
}

export function KymTab({ member: m }: { member: Member }) {
  const t = useT();
  const { can } = useSession();
  const [shown, setShown] = useState(false);
  const kyc = useApi<Kyc | null>(shown ? `/members/${m.id}/kyc` : null);
  const form = kyc.data ? { kym: kyc.data.kym } : null;

  return (
    <>
      <Section
        title={t('members.kymTitle')}
        action={can('member.kyc_update') && ['applied', 'active'].includes(m.status) && <Button variant="outlined" component={NextLink} href={`/members/${m.id}/kym`}>{t('members.updateKym')}</Button>}
      >
        {!shown ? (
          <>
            <Alert severity="warning" variant="outlined" sx={{ mb: 2 }}>{t('members.kymConfidential')}</Alert>
            <Button variant="contained" startIcon={<VisibilityOutlined />} onClick={() => setShown(true)}>{t('members.showKym')}</Button>
          </>
        ) : kyc.error !== undefined ? <ErrorState error={kyc.error} onRetry={kyc.reload} /> : kyc.loading ? <LoadingBlock /> : !kyc.data ? (
          <Typography>{t('members.noKym')}</Typography>
        ) : (
          <>
            <Box sx={{ display: 'flex', gap: 3, flexWrap: 'wrap', mb: 2, typography: 'body2' }}>
              <span>{t('members.kymVersion', { version: kyc.data.version })}</span>
              <span>{t('members.recordedAt')}: <BsDate ad={kyc.data.recorded_at} time /></span>
              <span>{t('members.verifiedInPerson')}: <BsDate ad={kyc.data.verified_in_person_at} time /></span>
            </Box>
            <KymSummary sections={SECTIONS.filter((s) => s.only !== 'apply')} form={form!} />
          </>
        )}
      </Section>
      <GapNotice gap="G6">{t('members.kymHistoryGap')}</GapNotice>
    </>
  );
}

export function ComplianceTab({ member: m, onChange }: { member: Member; onChange: (m: Member) => void }) {
  const t = useT();
  const toast = useToast();
  const { can } = useSession();
  const [risk, setRisk] = useState({ level: m.risk_level ?? 'low', reason: '' });
  const [decision, setDecision] = useState<'approve' | 'reject' | null>(null);
  const assess = useSubmit('risk', async () => (await api<{ data: Member }>(`/members/${m.id}/risk`, { method: 'POST', body: { risk_level: risk.level, reason: risk.reason } })).data);

  return (
    <>
      {can('member.risk_assess') && ['applied', 'active'].includes(m.status) && (
        <Section title={t('members.riskTitle')}>
          {m.is_pep && <Alert severity="warning" sx={{ mb: 2 }}>{t('members.pepRisk')}</Alert>}
          {assess.error !== undefined && <ErrorState error={assess.error} />}
          <Box component="form" method="post" noValidate sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'flex-start' }}
            onSubmit={async (e) => {
              e.preventDefault();
              const updated = await assess.submit();
              if (updated) { onChange(updated); toast.success(t('members.riskSaved')); setRisk({ ...risk, reason: '' }); }
            }}>
            <TextField select label={t('members.riskLevel')} value={risk.level} onChange={(e) => setRisk({ ...risk, level: e.target.value as typeof risk.level })} sx={{ minWidth: 160 }}>
              {(['low', 'medium', 'high'] as const).map((l) => <MenuItem key={l} value={l}>{t(`status.risk.${l}`)}</MenuItem>)}
            </TextField>
            <TextField required label={t('common.reason')} value={risk.reason} onChange={(e) => setRisk({ ...risk, reason: e.target.value })} sx={{ flexGrow: 1, minWidth: 260 }} error={!!assess.fieldErrors.reason} helperText={assess.fieldErrors.reason?.[0] ?? t('members.riskReasonHelp')} />
            <Button type="submit" variant="contained" disabled={!risk.reason.trim() || assess.submitting} sx={{ mt: 0.25 }}>{t('members.saveRisk')}</Button>
          </Box>
        </Section>
      )}
      {can('member.decide') && m.status === 'applied' && (
        <Section title={t('members.decisionTitle')}>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('members.decisionHelp')}</Typography>
          <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
            <Button variant="contained" color="success" onClick={() => setDecision('approve')}>{t('members.approve')}</Button>
            <Button variant="outlined" color="error" onClick={() => setDecision('reject')}>{t('members.reject')}</Button>
          </Box>
        </Section>
      )}
      {!(can('member.risk_assess') || can('member.decide')) && <Typography color="text.secondary">{t('errors.forbidden')}</Typography>}
      <ConfirmDialog open={decision === 'approve'} title={t('members.approveTitle', { name: m.full_name })} confirmLabel={t('members.approve')} onClose={() => setDecision(null)}
        onConfirm={async () => {
          const { data } = await api<{ data: Member }>(`/members/${m.id}/approve`, { method: 'POST' });
          onChange(data);
          toast.success(t('members.approved', { n: data.member_number ?? '' }));
        }}>
        {t('members.approveBody')}
      </ConfirmDialog>
      <ConfirmDialog open={decision === 'reject'} title={t('members.rejectTitle', { name: m.full_name })} confirmLabel={t('members.reject')} danger reasonLabel={t('members.rejectReason')} onClose={() => setDecision(null)}
        onConfirm={async (reason) => {
          const { data } = await api<{ data: Member }>(`/members/${m.id}/reject`, { method: 'POST', body: { reason } });
          onChange(data);
          toast.success(t('members.rejected'));
        }}>
        {t('members.rejectBody')}
      </ConfirmDialog>
    </>
  );
}
