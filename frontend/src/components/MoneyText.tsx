'use client';

import Box from '@mui/material/Box';
import { useT } from '@/i18n';
import { formatMoney, toNepaliDigits } from '@/lib/money';
import { usePreferences } from '@/lib/preferences';

/** Money as the API sent it (a decimal string), grouped 1,00,000.00, tabular figures. Never a JS number. */
export default function MoneyText({ value, prefix = false, strong = false, negativeColor = false }: { value: string | null | undefined; prefix?: boolean; strong?: boolean; negativeColor?: boolean }) {
  const t = useT();
  const { nepaliDigits } = usePreferences();
  if (value === null || value === undefined || value === '') return <Box component="span" sx={{ color: 'text.disabled' }}>—</Box>;
  const negative = value.trim().startsWith('-');
  return (
    <Box
      component="span"
      sx={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap', fontWeight: strong ? 650 : undefined, color: negative && negativeColor ? 'error.main' : undefined }}
    >
      {prefix && `${t('common.rs')} `}
      {formatMoney(value, { nepaliDigits })}
    </Box>
  );
}

/** Plain-text money for labels, CSV and aria strings. */
export function useMoney(): (value: string | null | undefined) => string {
  const { nepaliDigits } = usePreferences();
  return (value) => formatMoney(value, { nepaliDigits });
}

/** Digits in the user's chosen numerals (counts, percentages, numbers inside text). */
export function useDigits(): (value: string | number) => string {
  const { nepaliDigits } = usePreferences();
  return (value) => (nepaliDigits ? toNepaliDigits(String(value)) : String(value));
}
