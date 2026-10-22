'use client';

import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Step from '@mui/material/Step';
import StepButton from '@mui/material/StepButton';
import Stepper from '@mui/material/Stepper';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { useRouter } from 'next/navigation';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { SubmitButton } from '@/components/fields';
import { emptyApplication, type Form, KymSection, KymSummary, SECTIONS, sectionOf, toBody } from '@/components/members/kym';
import PageHeader from '@/components/PageHeader';
import { FormError } from '@/components/States';
import { useToast } from '@/components/Toasts';
import { useT } from '@/i18n';
import { api } from '@/lib/api';
import { useSubmit, useUnsavedChanges } from '@/lib/hooks';
import type { Member } from '@/lib/types';

const STEPS = SECTIONS; // every section, then the review step

/**
 * New member application: identity, Act s.30/s.32 declarations and the Schedule-1 KYM form,
 * one section per step, then a review. Nothing is saved until "Submit application".
 */
export default function NewMemberPage() {
  const t = useT();
  const router = useRouter();
  const toast = useToast();
  const theme = useTheme();
  const phone = useMediaQuery(theme.breakpoints.down('lg'));
  const [form, setForm] = useState<Form>(emptyApplication);
  const [step, setStep] = useState(0);
  const [done, setDone] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const review = step === STEPS.length;
  useUnsavedChanges(!done && JSON.stringify(form) !== JSON.stringify(emptyApplication()));

  const apply = useSubmit('member', async () => (await api<{ data: Member }>('/members', { method: 'POST', body: toBody(form) })).data);
  const errorSteps = new Set(Object.keys(apply.fieldErrors).map(sectionOf).filter(Boolean));

  useEffect(() => {
    heading.current?.focus();
  }, [step]);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!review) {
      setStep(step + 1);
      return;
    }
    const member = await apply.submit();
    if (member) {
      setDone(true);
      toast.success(t('members.applied'));
      router.push(`/members/${member.id}`);
    }
  }

  // Recompute after a failed submit (fieldErrors arrive asynchronously).
  useEffect(() => {
    const first = STEPS.findIndex((s) => Object.keys(apply.fieldErrors).some((k) => sectionOf(k) === s.id));
    // eslint-disable-next-line react-hooks/set-state-in-effect -- move to the first step the server rejected
    if (first >= 0) setStep(first);
  }, [apply.fieldErrors]);

  const current = STEPS[step];

  return (
    <Box component="form" method="post" noValidate onSubmit={onSubmit}>
      <PageHeader title={t('nav.newApplication')} crumbs={[{ label: t('nav.members'), href: '/members' }]} subtitle={t('members.applyHelp')} />
      <Alert severity="info" sx={{ mb: 2 }}>{t('members.inPerson')}</Alert>
      <FormError error={apply.error} />

      {phone ? (
        <Box sx={{ mb: 2 }}>
          <Typography variant="body2" color="text.secondary">{t('members.stepOf', { n: step + 1, total: STEPS.length + 1 })}</Typography>
          <LinearProgress variant="determinate" value={((step + 1) / (STEPS.length + 1)) * 100} sx={{ mt: 0.5, borderRadius: 1 }} aria-hidden />
        </Box>
      ) : (
        <Stepper nonLinear activeStep={step} sx={{ mb: 3, flexWrap: 'wrap', rowGap: 1 }}>
          {[...STEPS.map((s) => s.title), 'members.review' as const].map((title, i) => (
            <Step key={title} completed={false}>
              <StepButton onClick={() => setStep(i)} optional={i < STEPS.length && errorSteps.has(STEPS[i].id) ? <Typography variant="caption" color="error">{t('members.hasErrors')}</Typography> : undefined}>
                {t(title)}
              </StepButton>
            </Step>
          ))}
        </Stepper>
      )}

      <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 2 }}>
        <Typography ref={heading} tabIndex={-1} variant="h2" sx={{ mb: 2, outline: 'none' }}>{review ? t('members.review') : t(current.title)}</Typography>
        {review ? <KymSummary sections={STEPS} form={form} /> : <KymSection section={current} form={form} setForm={setForm} errors={apply.fieldErrors} />}
      </Paper>

      <Box sx={{ display: 'flex', gap: 1, justifyContent: 'space-between', flexWrap: 'wrap' }}>
        <Button disabled={step === 0} onClick={() => setStep(step - 1)}>{t('common.back')}</Button>
        {review
          ? <SubmitButton size="large" submitting={apply.submitting}>{t('members.submit')}</SubmitButton>
          : <SubmitButton>{t('members.next')}</SubmitButton>}
      </Box>
    </Box>
  );
}
