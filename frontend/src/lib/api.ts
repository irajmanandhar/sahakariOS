/**
 * Browser-side client for the Laravel API, reached through the same-origin
 * Next.js proxy (/api/*). Authentication is the HttpOnly session cookie, which
 * the browser sends automatically; this code never sees or stores it.
 */

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    /** Seconds from the Retry-After header (429 responses). */
    readonly retryAfter?: number,
  ) {
    super(message);
  }
}

export type User = { id: number; name: string; email: string };

export type Membership = {
  cooperative: { id: number; code: string; name: string; status: string };
  branch: { id: number; code: string; name: string };
};

export type BusinessDay = {
  date: string;
  date_bs: string;
  date_bs_text: string;
  fiscal_year: string;
  status: string;
};

export type Branch = { id: number; code: string; name: string; is_head_office: boolean; status: string };

/** CSRF token Laravel sets in the readable XSRF-TOKEN cookie (not a credential). */
function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : undefined;
}

export async function api<T>(path: string, options: { method?: string; body?: unknown } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  const token = xsrfToken();
  if (token) headers['X-XSRF-TOKEN'] = token;

  const response = await fetch(`/api${path}`, {
    method: options.method ?? 'GET',
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    credentials: 'same-origin',
    cache: 'no-store',
  });

  if (response.status === 204) return undefined as T;

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    const firstFieldError = body?.errors ? (Object.values(body.errors)[0] as string[] | undefined)?.[0] : undefined;
    const retryAfter = Number(response.headers.get('Retry-After')) || undefined;
    throw new ApiError(response.status, firstFieldError ?? body?.message ?? 'Request failed.', retryAfter);
  }

  return body as T;
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

/** User-facing text for a failed request. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      return `Too many attempts. Try again in ${error.retryAfter ?? 60} seconds.`;
    }
    return error.message;
  }
  console.error('Unexpected error', error);
  return 'Could not reach the server. Check your connection and try again.';
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
  // 409: no cooperative chosen yet; 403: the chosen one is no longer accessible.
  if (error.status === 409 || error.status === 403) {
    replace('/select-cooperative');
    return true;
  }
  return false;
}
