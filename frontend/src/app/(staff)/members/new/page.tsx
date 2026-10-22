'use client';

import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Container from '@mui/material/Container';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useRouter } from 'next/navigation';
import { type FormEvent, type ReactNode, useState } from 'react';
import { ApiError, api, errorMessage, type Member, redirectForAuthError } from '@/lib/api';
import { getPath, setPath } from '@/lib/paths';
import { useHydrated } from '@/lib/useHydrated';

/**
 * New member application: identity, Act s.30/s.32 declarations and the KYM
 * form of NCRA AML/CFT Instructions 2082, Schedule-1 (sections क-ञ).
 * The server validates everything; this form only collects and shows errors.
 */

type Option = [value: string, label: string];
const OCCUPATIONS: Option[] = [['agriculture', 'Agriculture'], ['business', 'Business'], ['job', 'Job / service'], ['foreign_employment', 'Foreign employment'], ['other', 'Other']];
const INCOME_BANDS: Option[] = [['up_to_4_lakh', 'Up to Rs 4 lakh'], ['4_to_10_lakh', 'Rs 4–10 lakh'], ['10_to_25_lakh', 'Rs 10–25 lakh'], ['25_to_50_lakh', 'Rs 25–50 lakh'], ['above_50_lakh', 'Above Rs 50 lakh']];

const initial = {
  gender: '',
  declares_nepali_citizen: false,
  declares_resides_in_working_area: false,
  declares_no_other_same_type_membership: false,
  kym: { pep: { is_pep: false }, self_declaration_accepted: false },
};

export default function NewMemberPage() {
  const router = useRouter();
  const [form, setForm] = useState<Record<string, unknown>>(initial);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const hydrated = useHydrated();

  const value = (path: string) => (getPath(form, path) ?? '') as string;
  const set = (path: string, v: unknown) => setForm((f) => setPath(f, path, v === '' ? undefined : v));

  const text = (path: string, label: string, props: { type?: string; required?: boolean; select?: Option[]; xs?: number } = {}): ReactNode => (
    <Grid size={{ xs: 12, sm: props.xs ?? 6 }} key={path}>
      <TextField
        fullWidth
        size="small"
        label={label}
        type={props.type ?? 'text'}
        required={props.required}
        select={!!props.select}
        value={value(path)}
        onChange={(e) => set(path, props.type === 'number' && e.target.value !== '' ? Number(e.target.value) : e.target.value)}
        error={!!errors[path]}
        helperText={errors[path]?.[0]}
        slotProps={props.type === 'date' ? { inputLabel: { shrink: true } } : undefined}
      >
        {props.select?.map(([v, l]) => (
          <MenuItem key={v} value={v}>
            {l}
          </MenuItem>
        ))}
      </TextField>
    </Grid>
  );

  const check = (path: string, label: string): ReactNode => (
    <Grid size={{ xs: 12 }} key={path}>
      <FormControlLabel control={<Checkbox checked={getPath(form, path) === true} onChange={(e) => set(path, e.target.checked)} />} label={label} />
      {errors[path] && <Typography color="error" variant="caption" sx={{ display: 'block' }}>{errors[path][0]}</Typography>}
    </Grid>
  );

  const section = (title: string, children: ReactNode) => (
    <Paper sx={{ p: 3, mb: 2 }}>
      <Typography variant="h6" component="h2" gutterBottom>
        {title}
      </Typography>
      <Grid container spacing={2}>
        {children}
      </Grid>
    </Paper>
  );

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    setMessage(null);
    try {
      const { data } = await api<{ data: Member }>('/members', { method: 'POST', body: form });
      router.push(`/members/${data.id}`);
    } catch (err) {
      if (redirectForAuthError(err, router.replace)) return;
      if (err instanceof ApiError) setErrors(err.fieldErrors);
      setMessage(errorMessage(err));
      setSubmitting(false);
      window.scrollTo({ top: 0 });
    }
  }

  const married = value('kym.marital_status') === 'married';
  const pep = getPath(form, 'kym.pep.is_pep') === true;

  return (
    <Container maxWidth="md" sx={{ py: 4 }} component="form" method="post" onSubmit={onSubmit} noValidate>
      <Button onClick={() => router.push('/members')}>← Members</Button>
      <Typography variant="h5" component="h1" sx={{ my: 2 }}>
        New member application
      </Typography>
      <Alert severity="info" sx={{ mb: 2 }}>
        The applicant must be present in person (AML/CFT Instruction 1(1)(ख)). Dates are AD (YYYY-MM-DD).
      </Alert>
      {message && <Alert severity="error" sx={{ mb: 2 }}>{message}</Alert>}

      {section('Identity', <>
        {text('full_name', 'Full name (as on citizenship)', { required: true })}
        {text('full_name_ne', 'Full name in Devanagari')}
        {text('date_of_birth', 'Date of birth', { type: 'date', required: true })}
        {text('gender', 'Gender', { required: true, select: [['female', 'Female'], ['male', 'Male'], ['other', 'Other']] })}
        {text('phone', 'Mobile number')}
        {text('email', 'Email', { type: 'email' })}
        {text('kym.citizenship.number', 'Citizenship certificate no.')}
        {text('kym.citizenship.issue_district', 'Issuing district')}
        {text('kym.citizenship.issue_date', 'Citizenship issue date', { type: 'date' })}
        {text('kym.national_id.number', 'National ID no.')}
        {text('kym.national_id.issue_date', 'National ID issue date', { type: 'date' })}
        {text('kym.passport_number', 'Passport no.')}
      </>)}

      {section('ख. Family', <>
        {text('kym.mother_name', "Mother's name", { required: true })}
        {text('kym.father_name', "Father's name", { required: true })}
        {text('kym.marital_status', 'Marital status', { required: true, select: [['married', 'Married'], ['unmarried', 'Unmarried'], ['single', 'Single']] })}
        {married && text('kym.spouse_name', "Spouse's name", { required: true })}
        {text('kym.family_type', 'Family type', { required: true, select: [['joint_one_kitchen', 'Joint, one kitchen'], ['joint_separate_kitchen', 'Joint, separate kitchens'], ['nuclear', 'Separated / nuclear']] })}
      </>)}

      {section('ग. Occupation', <>
        {text('kym.occupation.type', 'Main occupation', { required: true, select: OCCUPATIONS })}
        {text('kym.occupation.detail', 'Occupation detail')}
        {text('kym.pan_number', 'PAN')}
        {text('kym.spouse_occupation.type', "Spouse's occupation", { select: OCCUPATIONS })}
        {text('kym.other_earner.relation', 'Other main earner: relation')}
        {text('kym.other_earner.occupation', 'Other main earner: occupation')}
        {check('kym.pep.is_pep', 'The applicant or a family member holds a politically exposed / high public position (PEP)')}
        {pep && <>
          {text('kym.pep.name', 'PEP name', { required: true })}
          {text('kym.pep.relation', 'Relation to applicant', { required: true })}
          {text('kym.pep.position', 'Position / public role', { required: true, xs: 12 })}
        </>}
      </>)}

      {section('घ. Residence', <>
        {text('kym.permanent_address.province', 'Permanent: province', { required: true })}
        {text('kym.permanent_address.local_level', 'Permanent: municipality / rural municipality', { required: true })}
        {text('kym.permanent_address.ward', 'Permanent: ward', { type: 'number', required: true })}
        {text('kym.permanent_address.tole', 'Permanent: village / tole')}
        {text('kym.temporary_address.province', 'Temporary: province')}
        {text('kym.temporary_address.local_level', 'Temporary: local level')}
        {text('kym.temporary_address.ward', 'Temporary: ward', { type: 'number' })}
        {text('kym.temporary_address.tole', 'Temporary: tole')}
        {text('kym.working_area_residence', 'Lives in working area as', { required: true, select: [['permanent', 'Permanent resident'], ['temporary', 'Temporary resident']] })}
        {text('kym.years_in_working_area', 'Years in working area', { type: 'number' })}
        {text('kym.voter_id_number', 'Voter ID no.')}
        {text('kym.polling_station', 'Polling station')}
      </>)}

      {section('ङ–छ. Membership, income and expected transactions', <>
        {text('kym.membership_purpose', 'Purpose of membership', { required: true, xs: 12 })}
        {text('kym.annual_family_income_band', 'Annual family income', { required: true, select: INCOME_BANDS })}
        {text('kym.income_by_source.agriculture', 'Last FY income: agriculture (Rs)')}
        {text('kym.income_by_source.business', 'Last FY income: business (Rs)')}
        {text('kym.income_by_source.domestic_employment', 'Last FY income: employment in Nepal (Rs)')}
        {text('kym.income_by_source.foreign_employment', 'Last FY income: foreign employment (Rs)')}
        {text('kym.income_by_source.other', 'Last FY income: other (Rs)')}
        {text('kym.expected_transactions_per_year', 'Expected transactions per year', { type: 'number' })}
        {text('kym.expected_annual_deposit', 'Expected annual deposit (Rs)')}
        {text('kym.expected_loan_amount', 'Expected loan amount (Rs)')}
        {text('kym.notes', 'Other notes', { xs: 12 })}
      </>)}

      {section('ज. Declarations', <>
        {check('declares_nepali_citizen', 'I am a Nepali citizen (Cooperatives Act s.30)')}
        {check('declares_resides_in_working_area', "I reside in the cooperative's working area (s.30)")}
        {check('declares_no_other_same_type_membership', 'I am not a member of another cooperative of the same type (s.32)')}
        {check('kym.self_declaration_accepted', 'The details are true; I will report any change within 35 days and accept legal responsibility for false information')}
      </>)}

      <Button type="submit" variant="contained" size="large" disabled={!hydrated || submitting}>
        {submitting ? 'Submitting…' : 'Submit application'}
      </Button>
    </Container>
  );
}
