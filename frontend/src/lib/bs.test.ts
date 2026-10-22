import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { adToBs, bsToAd, fiscalYearOf, formatBs, nepalDateTime } from './bs';
import { BS_FIRST_YEAR, BS_MONTHS } from './bs-calendar';

describe('BS calendar', () => {
  it('matches the verified table the API uses', () => {
    // vitest runs in frontend/
    const php = readFileSync(resolve(process.cwd(), '../backend/resources/calendar/bikram-sambat.php'), 'utf8');
    const rows = [...php.matchAll(/(\d{4}) => \[([\d, ]+)\]/g)].map((m) => [Number(m[1]), m[2].split(',').map(Number)] as const);
    expect(rows.length).toBe(BS_MONTHS.length);
    rows.forEach(([year, months]) => expect(BS_MONTHS[year - BS_FIRST_YEAR]).toEqual(months));
  });

  it('converts known dates both ways', () => {
    expect(adToBs('1943-04-14')).toEqual({ year: 2000, month: 1, day: 1 });
    expect(formatBs(adToBs('2026-10-16')!)).toBe('2083-06-30');
    expect(formatBs(adToBs('2026-10-16T18:30:00+00:00')!)).toBe('2083-06-30');
    expect(bsToAd('2083-06-30')).toBe('2026-10-16');
    expect(bsToAd('2083-12-30')).toBe('2027-04-13');
    expect(formatBs({ year: 2083, month: 6, day: 30 }, { style: 'long' })).toBe('30 Asoj 2083');
    expect(formatBs({ year: 2083, month: 6, day: 30 }, { style: 'long', nepali: true })).toBe('३० असोज २०८३');
  });

  it('refuses to guess outside the verified range', () => {
    expect(adToBs('2027-04-14')).toBeNull();
    expect(adToBs('1943-04-13')).toBeNull();
    expect(bsToAd('2084-01-01')).toBeNull();
    expect(bsToAd('2083-02-32')).toBeNull();
  });

  it('names the fiscal year (Shrawan to Asar)', () => {
    expect(fiscalYearOf('2026-10-16')).toBe('2083/84');
    expect(fiscalYearOf('2026-07-16')).toBe('2082/83');
  });
});

describe('nepalDateTime', () => {
  it('uses the Nepal calendar date of UTC timestamps', () => {
    expect(nepalDateTime('2026-10-16 20:00:00')).toEqual({ date: '2026-10-17', time: '01:45' });
    expect(nepalDateTime('2026-10-16T06:00:00+00:00')).toEqual({ date: '2026-10-16', time: '11:45' });
    expect(nepalDateTime('nonsense')).toBeNull();
  });
});
