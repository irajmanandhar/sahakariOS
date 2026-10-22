import { BS_ANCHOR_AD, BS_FIRST_YEAR, BS_MONTHS } from './bs-calendar';
import { toNepaliDigits } from './money';

/**
 * AD ↔ BS for display, using the same verified table as the API (BsCalendar.php).
 * Outside the table's range the functions return null instead of guessing.
 * The API always receives AD dates.
 */

export const BS_MONTH_NAMES = ['Baisakh', 'Jestha', 'Asar', 'Shrawan', 'Bhadra', 'Asoj', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];
export const BS_MONTH_NAMES_NE = ['बैशाख', 'जेठ', 'असार', 'साउन', 'भदौ', 'असोज', 'कार्तिक', 'मंसिर', 'पुस', 'माघ', 'फागुन', 'चैत'];

const DAY_MS = 86_400_000;
const anchor = Date.parse(`${BS_ANCHOR_AD}T00:00:00Z`);
const pad = (n: number, w = 2) => String(n).padStart(w, '0');

export type BsParts = { year: number; month: number; day: number };

/** "2026-10-21" (or an ISO timestamp; its UTC calendar date is used) → { year, month, day } in BS. */
export function adToBs(ad: string): BsParts | null {
  const match = /^(\d{4}-\d{2}-\d{2})/.exec(ad);
  if (!match) return null;
  let days = Math.round((Date.parse(`${match[1]}T00:00:00Z`) - anchor) / DAY_MS);
  if (!Number.isFinite(days) || days < 0) return null;
  for (let i = 0; i < BS_MONTHS.length; i++) {
    for (let m = 0; m < 12; m++) {
      if (days < BS_MONTHS[i][m]) return { year: BS_FIRST_YEAR + i, month: m + 1, day: days + 1 };
      days -= BS_MONTHS[i][m];
    }
  }
  return null;
}

/** BS "2083-07-04" → AD "2026-10-21", or null if invalid / out of range. */
export function bsToAd(bs: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(bs);
  if (!match) return null;
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const lengths = BS_MONTHS[year - BS_FIRST_YEAR];
  if (!lengths || month < 1 || month > 12 || day < 1 || day > lengths[month - 1]) return null;
  let days = day - 1;
  for (let i = 0; i < year - BS_FIRST_YEAR; i++) days += BS_MONTHS[i].reduce((a, b) => a + b, 0);
  for (let m = 0; m < month - 1; m++) days += lengths[m];
  return new Date(anchor + days * DAY_MS).toISOString().slice(0, 10);
}

export function formatBs(parts: BsParts, options: { style?: 'iso' | 'long'; nepali?: boolean } = {}): string {
  const text = options.style === 'long'
    ? `${parts.day} ${(options.nepali ? BS_MONTH_NAMES_NE : BS_MONTH_NAMES)[parts.month - 1]} ${parts.year}`
    : `${parts.year}-${pad(parts.month)}-${pad(parts.day)}`;
  return options.nepali ? toNepaliDigits(text) : text;
}

/** Fiscal year (Shrawan–Asar) of an AD date, e.g. "2083/84". */
export function fiscalYearOf(ad: string): string | null {
  const bs = adToBs(ad);
  if (!bs) return null;
  const start = bs.month >= 4 ? bs.year : bs.year - 1;
  return `${start}/${pad((start + 1) % 100)}`;
}

/**
 * A timestamp from the API (ISO with offset, or a raw UTC database value "2026-10-16 20:00:00")
 * as Nepal calendar date and time. The date is what BS conversion must use, not the UTC date.
 */
export function nepalDateTime(timestamp: string): { date: string; time: string } | null {
  const iso = /[zZ]|[+-]\d{2}:?\d{2}$/.test(timestamp) ? timestamp : `${timestamp.replace(' ', 'T')}Z`;
  const instant = new Date(iso);
  if (Number.isNaN(instant.getTime())) return null;
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Kathmandu', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(instant).map((p) => [p.type, p.value]),
  );
  return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
}
