'use client';

import Button, { type ButtonProps } from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import TextField, { type TextFieldProps } from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Box from '@mui/material/Box';
import { useState } from 'react';
import { useT } from '@/i18n';
import { adToBs, bsToAd, formatBs } from '@/lib/bs';
import { formatMoney, parseMoneyInput } from '@/lib/money';
import { useHydrated } from '@/lib/useHydrated';

/**
 * Submit button for method="post" forms: disabled until React has hydrated (a native
 * submit before that could put passwords or ID numbers into the URL) and while submitting.
 */
export function SubmitButton({ submitting, children, ...props }: ButtonProps & { submitting?: boolean }) {
  const hydrated = useHydrated();
  return (
    <Button type="submit" variant="contained" {...props} disabled={!hydrated || submitting || props.disabled} startIcon={submitting ? <CircularProgress size={16} color="inherit" /> : props.startIcon}>
      {children}
    </Button>
  );
}

/**
 * Rupee amount typed as text. `value` is what the user typed; the canonical API string comes from
 * parseMoneyInput(value). Shows the grouped amount as confirmation, never converts to a number.
 */
export function MoneyField({ value, onChange, helperText, error, ...props }: Omit<TextFieldProps, 'onChange' | 'value'> & { value: string; onChange: (value: string) => void }) {
  const t = useT();
  const parsed = value ? parseMoneyInput(value) : null;
  const invalid = value !== '' && parsed === null;
  return (
    <TextField
      {...props}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      error={invalid || error}
      helperText={invalid ? t('common.invalidAmount') : helperText ?? (parsed ? `${t('common.rs')} ${formatMoney(parsed)}` : ' ')}
      slotProps={{ ...props.slotProps, htmlInput: { inputMode: 'decimal', autoComplete: 'off', ...(props.slotProps?.htmlInput as object) } }}
    />
  );
}

/**
 * Date entered in BS (default) or AD; `value`/`onChange` are always AD "YYYY-MM-DD" for the API.
 * The other calendar is shown underneath so the user can check the conversion.
 */
export function DateField({ value, onChange, label, helperText, error, required, name, sx, size = 'small', fullWidth }: {
  value: string; onChange: (ad: string) => void; label: string; helperText?: string; error?: boolean; required?: boolean; name?: string;
  sx?: TextFieldProps['sx']; size?: 'small' | 'medium'; fullWidth?: boolean;
}) {
  const t = useT();
  const [mode, setMode] = useState<'bs' | 'ad'>('bs');
  const bsOf = (ad: string) => { const p = ad ? adToBs(ad) : null; return p ? formatBs(p) : ''; };
  const [bsText, setBsText] = useState(() => bsOf(value));
  const [lastValue, setLastValue] = useState(value);
  if (value !== lastValue) {
    // value changed from outside (reset): follow it
    setLastValue(value);
    if (bsToAd(bsText) !== value) setBsText(bsOf(value));
  }
  const bsInvalid = mode === 'bs' && bsText !== '' && bsToAd(bsText) === null;
  const other = mode === 'bs' ? (value ? `${value} ${t('common.ad')}` : ' ') : (value && bsOf(value) ? `${bsOf(value)} ${t('common.bs')}` : ' ');

  return (
    <Box sx={{ display: 'flex', gap: 0.5, alignItems: 'flex-start', ...(sx as object) }}>
      {mode === 'bs' ? (
        <TextField
          size={size}
          fullWidth={fullWidth}
          name={name}
          required={required}
          label={`${label} (${t('common.bs')})`}
          placeholder="2083-07-04"
          value={bsText}
          onChange={(e) => {
            setBsText(e.target.value);
            const ad = bsToAd(e.target.value.trim());
            if (ad || e.target.value === '') {
              setLastValue(ad ?? '');
              onChange(ad ?? '');
            }
          }}
          error={bsInvalid || error}
          helperText={bsInvalid ? t('common.invalidBs') : helperText ?? other}
          slotProps={{ htmlInput: { inputMode: 'numeric', 'aria-description': t('common.bsFormat') } }}
        />
      ) : (
        <TextField
          size={size}
          fullWidth={fullWidth}
          name={name}
          required={required}
          type="date"
          label={`${label} (${t('common.ad')})`}
          value={value}
          onChange={(e) => {
            setLastValue(e.target.value);
            setBsText(bsOf(e.target.value));
            onChange(e.target.value);
          }}
          error={error}
          helperText={helperText ?? other}
          slotProps={{ inputLabel: { shrink: true } }}
        />
      )}
      <ToggleButtonGroup size="small" exclusive value={mode} onChange={(_, m) => m && setMode(m)} aria-label={label} sx={{ mt: size === 'small' ? 0.25 : 1 }}>
        <ToggleButton value="bs" aria-label={t('common.enterBs')} sx={{ px: 1, py: 0.5 }}>{t('common.bs')}</ToggleButton>
        <ToggleButton value="ad" aria-label={t('common.enterAd')} sx={{ px: 1, py: 0.5 }}>{t('common.ad')}</ToggleButton>
      </ToggleButtonGroup>
    </Box>
  );
}
