'use client';

import Box from '@mui/material/Box';
import { useT } from '@/i18n';
import { adToBs, formatBs, nepalDateTime } from '@/lib/bs';
import { toNepaliDigits } from '@/lib/money';
import { usePreferences } from '@/lib/preferences';

/** BS text for an AD date ("2083-06-30"), or null outside the verified calendar. Uses the API's BS when given. */
export function useBsText(): (ad: string | null | undefined, bs?: string | null, style?: 'iso' | 'long') => string | null {
  const { nepaliDigits, locale } = usePreferences();
  return (ad, bs, style = 'iso') => {
    if (bs && style === 'iso') return nepaliDigits ? toNepaliDigits(bs) : bs;
    const parts = ad ? adToBs(ad) : null;
    return parts ? formatBs(parts, { style, nepali: nepaliDigits || (style === 'long' && locale === 'ne') }) : null;
  };
}

/**
 * A date shown BS first with AD alongside: "2083-06-30 (2026-10-16 AD)".
 * Timestamps (ISO or raw UTC database values) are shown as the Nepal date and time.
 * Outside the verified BS range only AD is shown, never a guess.
 */
export default function BsDate({ ad, bs, time = false, stacked = false }: { ad: string | null | undefined; bs?: string | null; time?: boolean; stacked?: boolean }) {
  const t = useT();
  const bsText = useBsText();
  const { nepaliDigits } = usePreferences();
  if (!ad) return <Box component="span" sx={{ color: 'text.disabled' }}>—</Box>;
  const local = ad.length > 10 ? nepalDateTime(ad) : null;
  const day = local?.date ?? ad.slice(0, 10);
  const bsValue = bsText(day, local ? null : bs);
  const digits = (s: string) => (nepaliDigits ? toNepaliDigits(s) : s);
  const clock = time && local ? ` ${digits(local.time)}` : '';
  return (
    <Box component="span" sx={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap', display: stacked ? 'inline-flex' : 'inline', flexDirection: 'column' }}>
      <span>{bsValue ?? digits(day)}{clock}</span>
      {bsValue && (
        <Box component="span" sx={{ color: 'text.secondary', fontSize: '0.85em', ml: stacked ? 0 : 0.75 }}>
          {stacked ? `${digits(day)} ${t('common.ad')}` : `(${digits(day)} ${t('common.ad')})`}
        </Box>
      )}
    </Box>
  );
}
