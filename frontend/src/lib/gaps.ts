/**
 * Typed stand-ins for endpoints the API does not have yet. Each one is listed in
 * docs/frontend-gaps.md under the same id. They only use endpoints that exist and say
 * what they cannot do; none of them invents data or behaviour.
 */
import { api } from './api';
import type { Member, Page } from './types';

/** How many of the newest members the stand-in searches. */
export const MEMBER_SEARCH_WINDOW = 200;

let recent: { at: number; members: Member[] } | null = null;

/** Newest members, up to `limit`, following the cursor. */
export async function recentMembers(limit = MEMBER_SEARCH_WINDOW, query = ''): Promise<Member[]> {
  const members: Member[] = [];
  let cursor: string | null = null;
  do {
    const page: Page<Member> = await api<Page<Member>>(`/members${query ? `?${query}` : ''}${cursor ? `${query ? '&' : '?'}cursor=${encodeURIComponent(cursor)}` : ''}`);
    members.push(...page.data);
    cursor = page.next_cursor;
  } while (cursor && members.length < limit);
  return members.slice(0, limit);
}

/**
 * G5 — member search. Wanted: GET /api/members?search=<number or name>.
 * Until then: filter the newest MEMBER_SEARCH_WINDOW members in the browser (cached for a minute).
 */
export async function searchMembers(text: string): Promise<Member[]> {
  if (!recent || Date.now() - recent.at > 60_000) recent = { at: Date.now(), members: await recentMembers() };
  const needle = text.trim().toLowerCase();
  if (!needle) return [];
  return recent.members
    .filter((m) => String(m.member_number ?? '') === needle || m.full_name.toLowerCase().includes(needle) || (m.full_name_ne ?? '').includes(text.trim()))
    .slice(0, 10);
}
