import { describe, expect, it } from 'vitest';
import { compareMoney, formatMoney, parseMoneyInput, sumMoney, toNepaliDigits } from './money';

describe('formatMoney', () => {
  it('groups in the Nepali way and keeps two decimals', () => {
    expect(formatMoney('100000')).toBe('1,00,000.00');
    expect(formatMoney('1234567.5')).toBe('12,34,567.50');
    expect(formatMoney('999.99')).toBe('999.99');
    expect(formatMoney('-25000.00')).toBe('-25,000.00');
    expect(formatMoney('0.00')).toBe('0.00');
    expect(formatMoney('123456789012345678.91')).toBe('1,23,45,67,89,01,23,45,678.91');
  });

  it('never rounds and passes through what it cannot parse', () => {
    expect(formatMoney('1.005')).toBe('1.005');
    expect(formatMoney('n/a')).toBe('n/a');
    expect(formatMoney(null)).toBe('');
  });

  it('writes Nepali numerals on request', () => {
    expect(formatMoney('100000', { nepaliDigits: true })).toBe('१,००,०००.००');
    expect(toNepaliDigits('2083-07-04')).toBe('२०८३-०७-०४');
  });
});

describe('parseMoneyInput', () => {
  it('accepts grouped, Nepali-digit and plain amounts', () => {
    expect(parseMoneyInput('1,00,000.5')).toBe('100000.50');
    expect(parseMoneyInput('१००')).toBe('100.00');
    expect(parseMoneyInput(' 007 ')).toBe('7.00');
  });

  it('refuses negatives, three decimals and text', () => {
    expect(parseMoneyInput('-5')).toBeNull();
    expect(parseMoneyInput('1.005')).toBeNull();
    expect(parseMoneyInput('1e3')).toBeNull();
    expect(parseMoneyInput('')).toBeNull();
  });
});

describe('exact arithmetic', () => {
  it('sums without floating point error', () => {
    expect(sumMoney(['0.10', '0.20'])).toBe('0.30');
    expect(sumMoney(['9007199254740993.01', '0.01'])).toBe('9007199254740993.02');
    expect(sumMoney(['', null, '-1.50'])).toBe('-1.50');
    expect(compareMoney('100.00', '100')).toBe(0);
    expect(compareMoney('0.01', '0.02')).toBe(-1);
  });
});
