/** One key per user action: a retry of the same click reuses it, a new click gets a new one. */
export function newIdempotencyKey(prefix: string): string {
  return `${prefix}-${crypto.randomUUID()}`;
}
