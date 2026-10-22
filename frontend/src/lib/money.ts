/**
 * Money is a decimal string end to end ("1250.50"), exactly as the API sends it.
 * Nothing here converts to a JS number: arithmetic uses BigInt paisa.
 */

const DECIMAL = /^(-?)(\d+)(?:\.(\d+))?$/;
const NEPALI_DIGITS = '०१२३४५६७८९';

export function toNepaliDigits(text: string): string {
  return text.replace(/\d/g, (d) => NEPALI_DIGITS[Number(d)]);
}

/** Nepali grouping: last three digits, then pairs (12,34,567). */
function group(integer: string): string {
  if (integer.length <= 3) return integer;
  const head = integer.slice(0, -3);
  return `${head.replace(/\B(?=(\d{2})+$)/g, ',')},${integer.slice(-3)}`;
}

/**
 * "1234567.5" → "12,34,567.50". Never rounds: more than 2 decimals are shown as given.
 * Returns the input unchanged if it is not a decimal string.
 */
export function formatMoney(value: string | null | undefined, options: { nepaliDigits?: boolean } = {}): string {
  if (value === null || value === undefined || value === '') return '';
  const match = DECIMAL.exec(value.trim());
  if (!match) return value;
  const [, sign, integer, fraction = ''] = match;
  const text = `${sign}${group(integer.replace(/^0+(?=\d)/, ''))}.${fraction.padEnd(2, '0')}`;
  return options.nepaliDigits ? toNepaliDigits(text) : text;
}

/** User input ("1,00,000.5", "१००") → canonical API string ("100000.50"), or null if not a valid positive amount. */
export function parseMoneyInput(input: string): string | null {
  const ascii = input.trim().replace(/[०-९]/g, (d) => String(NEPALI_DIGITS.indexOf(d))).replace(/,/g, '');
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(ascii);
  if (!match) return null;
  return `${match[1].replace(/^0+(?=\d)/, '')}.${(match[2] ?? '').padEnd(2, '0')}`;
}

/** Exact paisa as BigInt. Throws on anything but a decimal string with at most 2 places. */
export function toPaisa(value: string): bigint {
  const match = DECIMAL.exec(value.trim());
  if (!match || (match[3]?.length ?? 0) > 2) throw new Error(`Not a money amount: ${value}`);
  const paisa = BigInt(match[2]) * 100n + BigInt((match[3] ?? '').padEnd(2, '0'));
  return match[1] ? -paisa : paisa;
}

export function fromPaisa(paisa: bigint): string {
  const sign = paisa < 0n ? '-' : '';
  const abs = paisa < 0n ? -paisa : paisa;
  return `${sign}${abs / 100n}.${String(abs % 100n).padStart(2, '0')}`;
}

/** Sum of money strings; blanks count as zero. */
export function sumMoney(values: (string | null | undefined)[]): string {
  return fromPaisa(values.reduce<bigint>((total, v) => total + (v ? toPaisa(v) : 0n), 0n));
}

export function compareMoney(a: string, b: string): number {
  const d = toPaisa(a) - toPaisa(b);
  return d === 0n ? 0 : d < 0n ? -1 : 1;
}
