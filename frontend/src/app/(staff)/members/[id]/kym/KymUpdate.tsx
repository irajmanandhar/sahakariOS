'use client';

import VisibilityOutlined from '@mui/icons-material/VisibilityOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import Typography from '@mui/material/Typography';
import { useParams, useRouter } from 'next/navigation';
import { type FormEvent, useEffect, useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import { type Form, KymSection, SECTIONS, sectionOf, toBody } from '@/components/members/kym';
import PageHeader from '@/components/PageHeader';
import { ErrorState, FormError, LoadingBlock } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useApi, useSubmit, useUnsavedChanges } from '@/lib/hooks';
import type { Kyc, Member } from '@/lib/types';

const UPDATE_SECTIONS = SECTIONS.filter((s) => s.only !== 'apply');

/**
 * KYM review / update: loads the current form (an audited view), edits it and saves a new
 * version (PUT /members/{id}/kyc). Saving counts as a review and sets the next review date.
 */
export default function KymUpdate() {
  const t = useT();
  const router = useRouter();
  const toast = useToast();
  const { id } = useParams<{ id: string }>();
  const member = useApi<Member>(`/members/${id}`);
  const [load, setLoad] = useState(false);
  const kyc = useApi<Kyc | null>(load ? `/members/${id}/kyc` : null);
  const [form, setForm] = useState<Form | null>(null);
  const [original, setOriginal] = useState('');
  const [section, setSection] = useState(0);
  const [confirm, setConfirm] = useState(false);
  const save = useSubmit('kyc', async () => (await api<{ data: Member }>(`/members/${id}/kyc`, { method: 'PUT', body: toBody(form!) })).data);
  useUnsavedChanges(form !== null && JSON.stringify(form) !== original);

  useEffect(() => {
    if (kyc.data === undefined || form !== null) return;
    const initial: Form = { kym: kyc.data?.kym ?? { pep: { is_pep: false } } };
    // The declaration is made again at every review.
    (initial.kym as Record<string, unknown>).self_declaration_accepted = false;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- start editing from the loaded version
    setForm(initial);
    setOriginal(JSON.stringify(initial));
  }, [kyc.data, form]);

  useEffect(() => {
    const first = UPDATE_SECTIONS.findIndex((s) => Object.keys(save.fieldErrors).some((k) => sectionOf(k) === s.id));
    // eslint-disable-next-line react-hooks/set-state-in-effect -- show the first section the server rejected
    if (first >= 0) setSection(first);
  }, [save.fieldErrors]);

  if (member.error !== undefined) return <ErrorState error={member.error} onRetry={member.reload} />;
  if (!member.data) return <LoadingBlock />;

  return (
    <Box component="form" method="post" noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); setConfirm(true); }}>
      <PageHeader
        title={t('members.updateKymTitle', { name: member.data.full_name })}
        crumbs={[{ label: t('nav.members'), href: '/members' }, { label: member.data.full_name, href: `/members/${id}?tab=kym` }]}
        subtitle={t('members.updateKymHelp')}
      />
      {!load ? (
        <Paper sx={{ p: 3 }}>
          <Alert severity="warning" variant="outlined" sx={{ mb: 2 }}>{t('members.kymConfidential')}</Alert>
          <Button variant="contained" startIcon={<VisibilityOutlined />} onClick={() => setLoad(true)}>{t('members.loadKym')}</Button>
        </Paper>
      ) : kyc.error !== undefined ? <ErrorState error={kyc.error} onRetry={kyc.reload} /> : !form ? <LoadingBlock rows={6} /> : (
        <>
          <FormError error={save.error} />
          <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}>
            <Tabs value={section} onChange={(_, v) => setSection(v)} variant="scrollable" allowScrollButtonsMobile aria-label={t('members.sections')}>
              {UPDATE_SECTIONS.map((s, i) => <Tab key={s.id} value={i} label={t(s.title)} sx={{ color: Object.keys(save.fieldErrors).some((k) => sectionOf(k) === s.id) ? 'error.main' : undefined }} />)}
            </Tabs>
          </Box>
          <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 2 }}>
            <Typography variant="h2" sx={{ mb: 2 }}>{t(UPDATE_SECTIONS[section].title)}</Typography>
            <KymSection section={UPDATE_SECTIONS[section]} form={form} setForm={(f) => setForm((old) => f(old!))} errors={save.fieldErrors} />
          </Paper>
          <Box sx={{ display: 'flex', gap: 1, justifyContent: 'space-between', flexWrap: 'wrap' }}>
            <Button onClick={() => router.push(`/members/${id}?tab=kym`)}>{t('common.cancel')}</Button>
            <Button type="submit" variant="contained" disabled={save.submitting}>{t('members.saveKym')}</Button>
          </Box>
          <ConfirmDialog open={confirm} title={t('members.saveKymTitle')} confirmLabel={t('members.saveKym')} onClose={() => setConfirm(false)}
            onConfirm={async () => {
              await save.attempt();
              setOriginal(JSON.stringify(form));
              toast.success(t('members.kymSaved'));
              router.push(`/members/${id}?tab=kym`);
            }}>
            {t('members.saveKymBody')}
          </ConfirmDialog>
        </>
      )}
    </Box>
  );
}
