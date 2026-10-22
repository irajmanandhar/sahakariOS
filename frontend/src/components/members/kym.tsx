'use client';

import AddOutlined from '@mui/icons-material/AddOutlined';
import DeleteOutlined from '@mui/icons-material/DeleteOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import Grid from '@mui/material/Grid';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { type MessageKey, useT } from '@/i18n';
import { parseMoneyInput } from '@/lib/money';
import { getPath, setPath } from '@/lib/paths';
import { DateField, MoneyField } from '../fields';

/**
 * The KYM form of NCRA AML/CFT Instructions 2082, Schedule-1 (sections क–ञ), as data.
 * Field paths match the API (KymForm::rules, MemberController::coreRules). The server validates
 * everything; this only collects, shows server errors next to their fields and maps them to steps.
 */

export type Form = Record<string, unknown>;
type Option = [value: string, label: MessageKey];
type Field =
  | { kind: 'text' | 'number' | 'email' | 'money' | 'date'; path: string; label: MessageKey; required?: boolean; options?: Option[]; wide?: boolean; when?: (f: Form) => boolean }
  | { kind: 'check'; path: string; label: MessageKey; when?: (f: Form) => boolean }
  | { kind: 'list'; path: string; label: MessageKey; columns: { key: string; label: MessageKey; number?: boolean }[] };
export type Section = { id: string; title: MessageKey; help?: MessageKey; fields: Field[]; only?: 'apply' };

const OCCUPATIONS: Option[] = [['agriculture', 'kym.occ.agriculture'], ['business', 'kym.occ.business'], ['job', 'kym.occ.job'], ['foreign_employment', 'kym.occ.foreign'], ['other', 'kym.occ.other']];
const INCOME_BANDS: Option[] = [['up_to_4_lakh', 'kym.income.b1'], ['4_to_10_lakh', 'kym.income.b2'], ['10_to_25_lakh', 'kym.income.b3'], ['25_to_50_lakh', 'kym.income.b4'], ['above_50_lakh', 'kym.income.b5']];

const address = (prefix: 'permanent' | 'temporary', required: boolean): Field[] => [
  { kind: 'text', path: `kym.${prefix}_address.province`, label: `kym.${prefix}.province`, required },
  { kind: 'text', path: `kym.${prefix}_address.local_level`, label: `kym.${prefix}.localLevel`, required },
  { kind: 'number', path: `kym.${prefix}_address.ward`, label: `kym.${prefix}.ward`, required },
  { kind: 'text', path: `kym.${prefix}_address.tole`, label: `kym.${prefix}.tole` },
  { kind: 'text', path: `kym.${prefix}_address.house_number`, label: `kym.${prefix}.house` },
];

export const SECTIONS: Section[] = [
  {
    id: 'identity', title: 'kym.s.identity', only: 'apply', fields: [
      { kind: 'text', path: 'full_name', label: 'kym.fullName', required: true },
      { kind: 'text', path: 'full_name_ne', label: 'kym.fullNameNe' },
      { kind: 'date', path: 'date_of_birth', label: 'kym.dob', required: true },
      { kind: 'text', path: 'gender', label: 'kym.gender', required: true, options: [['female', 'kym.female'], ['male', 'kym.male'], ['other', 'kym.otherGender']] },
      { kind: 'text', path: 'phone', label: 'kym.phone' },
      { kind: 'email', path: 'email', label: 'kym.email' },
    ],
  },
  {
    id: 'ids', title: 'kym.s.ids', help: 'kym.idsHelp', fields: [
      { kind: 'text', path: 'kym.citizenship.number', label: 'kym.citizenshipNo' },
      { kind: 'text', path: 'kym.citizenship.issue_district', label: 'kym.issueDistrict' },
      { kind: 'date', path: 'kym.citizenship.issue_date', label: 'kym.citizenshipDate' },
      { kind: 'text', path: 'kym.national_id.number', label: 'kym.nationalId' },
      { kind: 'date', path: 'kym.national_id.issue_date', label: 'kym.nationalIdDate' },
      { kind: 'text', path: 'kym.passport_number', label: 'kym.passport' },
      { kind: 'text', path: 'kym.voter_id_number', label: 'kym.voterId' },
      { kind: 'text', path: 'kym.polling_station', label: 'kym.pollingStation' },
    ],
  },
  {
    id: 'family', title: 'kym.s.family', fields: [
      { kind: 'text', path: 'kym.mother_name', label: 'kym.mother', required: true },
      { kind: 'text', path: 'kym.father_name', label: 'kym.father', required: true },
      { kind: 'text', path: 'kym.marital_status', label: 'kym.marital', required: true, options: [['married', 'kym.married'], ['unmarried', 'kym.unmarried'], ['single', 'kym.single']] },
      { kind: 'text', path: 'kym.spouse_name', label: 'kym.spouse', required: true, when: (f) => getPath(f, 'kym.marital_status') === 'married' },
      { kind: 'text', path: 'kym.family_type', label: 'kym.familyType', required: true, options: [['joint_one_kitchen', 'kym.joint1'], ['joint_separate_kitchen', 'kym.joint2'], ['nuclear', 'kym.nuclear']] },
    ],
  },
  {
    id: 'occupation', title: 'kym.s.occupation', fields: [
      { kind: 'text', path: 'kym.occupation.type', label: 'kym.occupation', required: true, options: OCCUPATIONS },
      { kind: 'text', path: 'kym.occupation.detail', label: 'kym.occupationDetail', when: (f) => getPath(f, 'kym.occupation.type') !== undefined, required: true },
      { kind: 'text', path: 'kym.pan_number', label: 'kym.pan' },
      { kind: 'text', path: 'kym.spouse_occupation.type', label: 'kym.spouseOccupation', options: OCCUPATIONS },
      { kind: 'text', path: 'kym.spouse_occupation.detail', label: 'kym.spouseOccupationDetail' },
      { kind: 'text', path: 'kym.other_earner.relation', label: 'kym.otherEarnerRelation' },
      { kind: 'text', path: 'kym.other_earner.occupation', label: 'kym.otherEarnerOccupation' },
      { kind: 'check', path: 'kym.pep.is_pep', label: 'kym.isPep' },
      { kind: 'text', path: 'kym.pep.name', label: 'kym.pepName', required: true, when: (f) => getPath(f, 'kym.pep.is_pep') === true },
      { kind: 'text', path: 'kym.pep.relation', label: 'kym.pepRelation', required: true, when: (f) => getPath(f, 'kym.pep.is_pep') === true },
      { kind: 'text', path: 'kym.pep.position', label: 'kym.pepPosition', required: true, wide: true, when: (f) => getPath(f, 'kym.pep.is_pep') === true },
    ],
  },
  {
    id: 'residence', title: 'kym.s.residence', fields: [
      ...address('permanent', true),
      ...address('temporary', false),
      { kind: 'text', path: 'kym.working_area_residence', label: 'kym.workingArea', required: true, options: [['permanent', 'kym.permanentResident'], ['temporary', 'kym.temporaryResident']] },
      { kind: 'number', path: 'kym.years_in_working_area', label: 'kym.yearsInArea' },
    ],
  },
  {
    id: 'membership', title: 'kym.s.membership', fields: [
      { kind: 'text', path: 'kym.membership_purpose', label: 'kym.purpose', required: true, wide: true },
      { kind: 'list', path: 'kym.other_cooperative_memberships', label: 'kym.otherMemberships', columns: [{ key: 'cooperative', label: 'kym.cooperative' }, { key: 'membership_number', label: 'kym.membershipNo' }, { key: 'purpose', label: 'kym.listPurpose' }] },
      { kind: 'list', path: 'kym.family_other_memberships', label: 'kym.familyOtherMemberships', columns: [{ key: 'name', label: 'kym.name' }, { key: 'relation', label: 'kym.relation' }, { key: 'cooperative', label: 'kym.cooperative' }] },
      { kind: 'list', path: 'kym.family_members_here', label: 'kym.familyHere', columns: [{ key: 'name', label: 'kym.name' }, { key: 'membership_number', label: 'kym.membershipNo', number: true }] },
    ],
  },
  {
    id: 'income', title: 'kym.s.income', fields: [
      { kind: 'text', path: 'kym.annual_family_income_band', label: 'kym.incomeBand', required: true, options: INCOME_BANDS },
      { kind: 'money', path: 'kym.income_by_source.agriculture', label: 'kym.incAgriculture' },
      { kind: 'money', path: 'kym.income_by_source.business', label: 'kym.incBusiness' },
      { kind: 'money', path: 'kym.income_by_source.domestic_employment', label: 'kym.incDomestic' },
      { kind: 'money', path: 'kym.income_by_source.foreign_employment', label: 'kym.incForeign' },
      { kind: 'money', path: 'kym.income_by_source.other', label: 'kym.incOther' },
      { kind: 'money', path: 'kym.initial_deposits.share', label: 'kym.initShare' },
      { kind: 'money', path: 'kym.initial_deposits.savings', label: 'kym.initSavings' },
      { kind: 'money', path: 'kym.initial_deposits.other', label: 'kym.initOther' },
      { kind: 'number', path: 'kym.expected_transactions_per_year', label: 'kym.expectedTxn' },
      { kind: 'money', path: 'kym.expected_annual_deposit', label: 'kym.expectedDeposit' },
      { kind: 'money', path: 'kym.expected_loan_amount', label: 'kym.expectedLoan' },
      { kind: 'text', path: 'kym.notes', label: 'kym.notes', wide: true },
    ],
  },
  {
    id: 'declarations', title: 'kym.s.declarations', fields: [
      { kind: 'check', path: 'declares_nepali_citizen', label: 'kym.declCitizen', when: (f) => '__apply' in f },
      { kind: 'check', path: 'declares_resides_in_working_area', label: 'kym.declResides', when: (f) => '__apply' in f },
      { kind: 'check', path: 'declares_no_other_same_type_membership', label: 'kym.declNoOther', when: (f) => '__apply' in f },
      { kind: 'check', path: 'kym.self_declaration_accepted', label: 'kym.declTrue' },
      { kind: 'list', path: 'kym.recommender_member_numbers', label: 'kym.recommenders', columns: [{ key: '', label: 'kym.membershipNo', number: true }] },
    ],
  },
];

export const MONEY_PATHS = SECTIONS.flatMap((s) => s.fields).filter((f) => f.kind === 'money').map((f) => f.path);

/** Empty form for a new application (`__apply` marks the core identity and Act s.30/s.32 declarations as in scope). */
export const emptyApplication = (): Form => ({
  __apply: true,
  declares_nepali_citizen: false,
  declares_resides_in_working_area: false,
  declares_no_other_same_type_membership: false,
  kym: { pep: { is_pep: false }, self_declaration_accepted: false },
});

/** Body for the API: canonical money strings, no empty strings, no UI markers. */
export function toBody(form: Form): Form {
  let body: Form = JSON.parse(JSON.stringify(form));
  delete body.__apply;
  for (const path of MONEY_PATHS) {
    const value = getPath(body, path);
    if (typeof value === 'string') body = setPath(body, path, parseMoneyInput(value) ?? value);
  }
  return body;
}

/** The section a server error belongs to ("kym.permanent_address.ward" → residence). */
export function sectionOf(errorPath: string): string | undefined {
  return SECTIONS.find((s) => s.fields.some((f) => errorPath === f.path || errorPath.startsWith(`${f.path}.`)))?.id
    ?? (errorPath.startsWith('kym.') ? undefined : 'identity');
}

export function KymSection({ section, form, setForm, errors }: { section: Section; form: Form; setForm: (f: (old: Form) => Form) => void; errors: Record<string, string[]> }) {
  const t = useT();
  const set = (path: string, v: unknown) => setForm((f) => setPath(f, path, v === '' ? undefined : v));
  const error = (path: string) => errors[path]?.[0];

  return (
    <Grid container spacing={2}>
      {section.help && <Grid size={12}><Typography variant="body2" color="text.secondary">{t(section.help)}</Typography></Grid>}
      {section.fields.filter((f) => f.kind === 'list' || !f.when || f.when(form)).map((f) => {
        if (f.kind === 'check') {
          return (
            <Grid size={12} key={f.path}>
              <FormControlLabel control={<Checkbox checked={getPath(form, f.path) === true} onChange={(e) => set(f.path, e.target.checked)} />} label={t(f.label)} />
              {error(f.path) && <FormHelperText error>{error(f.path)}</FormHelperText>}
            </Grid>
          );
        }
        if (f.kind === 'list') {
          const rows = (getPath(form, f.path) as unknown[] | undefined) ?? [];
          const scalar = f.columns.length === 1 && f.columns[0].key === '';
          const update = (next: unknown[]) => set(f.path, next.length ? next : undefined);
          return (
            <Grid size={12} key={f.path}>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>{t(f.label)}</Typography>
              {rows.map((row, i) => (
                <Box key={i} sx={{ display: 'flex', gap: 1, mb: 1, flexWrap: { xs: 'wrap', sm: 'nowrap' } }}>
                  {f.columns.map((c) => {
                    const path = scalar ? `${f.path}.${i}` : `${f.path}.${i}.${c.key}`;
                    const value = scalar ? row : (row as Record<string, unknown>)[c.key];
                    return (
                      <TextField key={c.key || 'v'} fullWidth label={t(c.label)} type={c.number ? 'number' : 'text'} value={value ?? ''}
                        error={!!error(path)} helperText={error(path)}
                        onChange={(e) => {
                          const v = c.number && e.target.value !== '' ? Number(e.target.value) : e.target.value;
                          update(rows.map((r, n) => (n !== i ? r : scalar ? v : { ...(r as object), [c.key]: v })));
                        }} />
                    );
                  })}
                  <IconButton aria-label={t('kym.removeRow')} onClick={() => update(rows.filter((_, n) => n !== i))}><DeleteOutlined /></IconButton>
                </Box>
              ))}
              <Button size="small" startIcon={<AddOutlined />} onClick={() => update([...rows, scalar ? '' : {}])}>{t('kym.addRow')}</Button>
              {error(f.path) && <FormHelperText error>{error(f.path)}</FormHelperText>}
            </Grid>
          );
        }
        const value = (getPath(form, f.path) ?? '') as string;
        const common = { required: f.required, error: !!error(f.path), helperText: error(f.path) };
        return (
          <Grid size={{ xs: 12, sm: f.wide ? 12 : 6 }} key={f.path}>
            {f.kind === 'date' ? (
              <DateField label={t(f.label)} value={value} onChange={(v) => set(f.path, v)} fullWidth {...common} />
            ) : f.kind === 'money' ? (
              <MoneyField fullWidth label={t(f.label)} value={value} onChange={(v) => set(f.path, v)} {...common} />
            ) : (
              <TextField
                fullWidth
                label={t(f.label)}
                type={f.kind === 'number' ? 'number' : f.kind === 'email' ? 'email' : 'text'}
                select={!!f.options}
                value={value}
                onChange={(e) => set(f.path, f.kind === 'number' && e.target.value !== '' ? Number(e.target.value) : e.target.value)}
                {...common}
              >
                {f.options?.map(([v, l]) => <MenuItem key={v} value={v}>{t(l)}</MenuItem>)}
              </TextField>
            )}
          </Grid>
        );
      })}
    </Grid>
  );
}

/** Read-only summary of what was entered, for the review step. */
export function KymSummary({ sections, form }: { sections: Section[]; form: Form }) {
  const t = useT();
  const show = (v: unknown): string => (v === true ? t('common.yes') : v === false ? t('common.no') : Array.isArray(v) ? v.map((x) => (typeof x === 'object' ? Object.values(x as object).join(' / ') : String(x))).join('; ') : String(v));
  return (
    <Box sx={{ display: 'grid', gap: 2 }}>
      {sections.map((s) => {
        const filled = s.fields.filter((f) => (f.kind === 'list' || !f.when || f.when(form)) && getPath(form, f.path) !== undefined && getPath(form, f.path) !== '');
        return (
          <Box key={s.id}>
            <Typography variant="subtitle2" sx={{ mb: 0.5 }}>{t(s.title)}</Typography>
            {filled.length === 0 ? <Typography variant="body2" color="text.secondary">—</Typography> : (
              <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: { xs: '1fr', sm: 'minmax(180px, 1fr) 2fr' }, columnGap: 2, typography: 'body2' }}>
                {filled.map((f) => {
                  const raw = getPath(form, f.path);
                  const option = 'options' in f ? f.options?.find(([v]) => v === raw) : undefined;
                  return (
                    <Box key={f.path} sx={{ display: 'contents' }}>
                      <Typography component="dt" variant="body2" color="text.secondary">{t(f.label)}</Typography>
                      <Typography component="dd" variant="body2" sx={{ m: 0, mb: 0.5, wordBreak: 'break-word' }}>{option ? t(option[1]) : show(raw)}</Typography>
                    </Box>
                  );
                })}
              </Box>
            )}
          </Box>
        );
      })}
    </Box>
  );
}
