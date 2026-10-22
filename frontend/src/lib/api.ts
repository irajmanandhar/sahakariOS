/**
 * Browser-side client for the Laravel API, reached through the same-origin
 * Next.js proxy (/api/*). Authentication is the HttpOnly session cookie, which
 * the browser sends automatically; this code never sees or stores it.
 */

import type { Membership } from './types';

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    /** Seconds from the Retry-After header (429 responses). */
    readonly retryAfter?: number,
    /** Laravel validation errors keyed by field path, e.g. "kym.mother_name". */
    readonly fieldErrors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

export type { Branch, BusinessDay, Member, Membership, User } from './types';

/** Fires after every response from the API: the server's idle timer restarted (see IdleTimeout). */
export const apiActivity = typeof window === 'undefined' ? null : new EventTarget();

/** CSRF token Laravel sets in the readable XSRF-TOKEN cookie (not a credential). */
function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : undefined;
}

export async function api<T>(path: string, options: { method?: string; body?: unknown; form?: FormData; idempotencyKey?: string } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  // For FormData the browser sets the multipart Content-Type (with boundary) itself.
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  const token = xsrfToken();
  if (token) headers['X-XSRF-TOKEN'] = token;
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

  const response = await fetch(`/api${path}`, {
    method: options.method ?? (options.form ? 'POST' : 'GET'),
    headers,
    body: options.form ?? (options.body === undefined ? undefined : JSON.stringify(options.body)),
    credentials: 'same-origin',
    cache: 'no-store',
  });
  apiActivity?.dispatchEvent(new Event('response'));

  if (response.status === 204) return undefined as T;

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    const firstFieldError = body?.errors ? (Object.values(body.errors)[0] as string[] | undefined)?.[0] : undefined;
    const retryAfter = Number(response.headers.get('Retry-After')) || undefined;
    throw new ApiError(response.status, firstFieldError ?? body?.message ?? 'Request failed.', retryAfter, body?.errors ?? {});
  }

  return body as T;
}

/** Fetches a private file (member document) for preview; same session and checks as api(). */
export async function apiBlob(path: string): Promise<Blob> {
  const response = await fetch(`/api${path}`, { credentials: 'same-origin', cache: 'no-store' });
  apiActivity?.dispatchEvent(new Event('response'));
  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new ApiError(response.status, body?.message ?? 'Request failed.');
  }
  return response.blob();
}

/** Where to go after a successful staff login. */
export async function destinationAfterLogin(): Promise<string> {
  const { data } = await api<{ data: Membership[] }>('/auth/cooperatives');

  if (data.length === 1) {
    await api('/auth/cooperative', { method: 'PUT', body: { cooperative_id: data[0].cooperative.id } });
    return '/';
  }

  return '/select-cooperative';
}

/**
 * Sends the user to the right screen when the API says they cannot continue.
 * Returns true if it redirected.
 */
export function redirectForAuthError(error: unknown, replace: (path: string) => void): boolean {
  if (!(error instanceof ApiError)) return false;
  if (error.status === 401) {
    replace('/login');
    return true;
  }
  // 409: no cooperative chosen yet. A 403 is shown as a message instead: it can mean
  // "your role does not allow this" as well as "no longer a member here".
  if (error.status === 409) {
    replace('/select-cooperative');
    return true;
  }
  return false;
}
