import { nepalDateTime } from './bs';
import type { AuditLog } from './types';

export type AuditFilters = { action: string; actor: string; subject: string; from: string; to: string };

/** Client-side filter over loaded audit rows (gap G1: the API has no filters). Dates are Nepal calendar dates. */
export function auditFilter(rows: AuditLog[], f: AuditFilters): AuditLog[] {
  const actor = f.actor.trim().toLowerCase();
  const subject = f.subject.trim().toLowerCase();
  return rows.filter((r) => {
    const day = nepalDateTime(r.occurred_at)?.date ?? r.occurred_at.slice(0, 10);
    return (!f.action || r.action === f.action)
      && (!actor || `${r.actor_type ?? ''} ${r.actor_id ?? ''}`.toLowerCase().includes(actor))
      && (!subject || `${r.subject_type ?? ''} ${r.subject_id ?? ''}`.toLowerCase().includes(subject))
      && (!f.from || day >= f.from)
      && (!f.to || day <= f.to);
  });
}

/**
 * Missing sequence numbers among loaded rows (newest first). One cooperative's chain is numbered
 * 1, 2, 3 … without gaps, so a gap means rows are missing. This is a hint only: verifying the
 * hashes needs `php artisan audit:verify` (the API does not return them).
 */
export function sequenceGaps(rows: AuditLog[]): [number, number][] {
  const gaps: [number, number][] = [];
  for (let i = 1; i < rows.length; i++) {
    const newer = rows[i - 1].sequence;
    const older = rows[i].sequence;
    if (newer - older > 1) gaps.push([older + 1, newer - 1]);
  }
  return gaps;
}
