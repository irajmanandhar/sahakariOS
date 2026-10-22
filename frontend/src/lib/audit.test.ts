import { describe, expect, it } from 'vitest';
import { auditFilter, sequenceGaps } from './audit';
import type { AuditLog } from './types';

const row = (sequence: number, action = 'journal.posted', occurred_at = '2026-10-16 05:00:00', actor_id = 3): AuditLog => ({
  id: sequence, sequence, actor_type: 'user', actor_id, action, subject_type: 'JournalEntry', subject_id: sequence, data: null, ip_address: null, occurred_at,
});

describe('sequenceGaps', () => {
  it('finds missing sequence numbers in a newest-first chain', () => {
    expect(sequenceGaps([row(10), row(9), row(8)])).toEqual([]);
    expect(sequenceGaps([row(10), row(7), row(6)])).toEqual([[8, 9]]);
  });
});

describe('auditFilter', () => {
  const rows = [row(3, 'member.approved', '2026-10-16 20:00:00', 2), row(2, 'journal.posted', '2026-10-16 05:00:00'), row(1)];

  it('filters by action, actor, subject and Nepal date', () => {
    const none = { action: '', actor: '', subject: '', from: '', to: '' };
    expect(auditFilter(rows, { ...none, action: 'member.approved' }).map((r) => r.id)).toEqual([3]);
    expect(auditFilter(rows, { ...none, actor: 'user 2' }).map((r) => r.id)).toEqual([3]);
    expect(auditFilter(rows, { ...none, subject: 'journalentry 1' }).map((r) => r.id)).toEqual([1]);
    // 20:00 UTC on the 16th is the 17th in Nepal
    expect(auditFilter(rows, { ...none, from: '2026-10-17' }).map((r) => r.id)).toEqual([3]);
  });
});
