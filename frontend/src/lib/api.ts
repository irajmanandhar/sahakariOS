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
    /** Laravel validation errors keyed by field path, e.g. "kym.mother_name". */
    readonly fieldErrors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

export type User = { id: number; name: string; email: string };

export type Membership = {
  cooperative: { id: number; code: string; name: string; status: string };
  branch: { id: number; code: string; name: string };
  /** Present on the current selection only. Hides actions in the UI; the API enforces them. */
  permissions?: string[];
};

export type BusinessDay = {
  date: string;
  date_bs: string;
  date_bs_text: string;
  fiscal_year: string;
  status: string;
};

export type Member = {
  id: number;
  member_number: number | null;
  status: 'applied' | 'active' | 'rejected' | 'exited';
  full_name: string;
  full_name_ne: string | null;
  gender: string;
  date_of_birth: string;
  date_of_birth_bs: string | null;
  phone: string | null;
  is_pep: boolean;
  risk_level: 'low' | 'medium' | 'high' | null;
  next_kyc_review_on: string | null;
  applied_on: string;
  days_to_decide: number | null;
  decided_at: string | null;
  decision_reason: string | null;
  documents?: { id: number; type: string; mime_type: string; size_bytes: number; uploaded_at: string }[];
};

/** Permissions of the current cooperative selection (UI hint only; the API enforces). */
export async function currentPermissions(): Promise<string[]> {
  const { data } = await api<{ data: Membership | null }>('/auth/cooperative');
  return data?.permissions ?? [];
}

export type Branch = { id: number; code: string; name: string; is_head_office: boolean; status: string };

/** CSRF token Laravel sets in the readable XSRF-TOKEN cookie (not a credential). */
function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : undefined;
}

export async function api<T>(path: string, options: { method?: string; body?: unknown; form?: FormData } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  // For FormData the browser sets the multipart Content-Type (with boundary) itself.
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  const token = xsrfToken();
  if (token) headers['X-XSRF-TOKEN'] = token;

  const response = await fetch(`/api${path}`, {
    method: options.method ?? (options.form ? 'POST' : 'GET'),
    headers,
    body: options.form ?? (options.body === undefined ? undefined : JSON.stringify(options.body)),
    credentials: 'same-origin',
    cache: 'no-store',
  });

  if (response.status === 204) return undefined as T;

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    const firstFieldError = body?.errors ? (Object.values(body.errors)[0] as string[] | undefined)?.[0] : undefined;
    const retryAfter = Number(response.headers.get('Retry-After')) || undefined;
    throw new ApiError(response.status, firstFieldError ?? body?.message ?? 'Request failed.', retryAfter, body?.errors ?? {});
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
  // 409: no cooperative chosen yet. A 403 is shown as a message instead: it can mean
  // "your role does not allow this" as well as "no longer a member here".
  if (error.status === 409) {
    replace('/select-cooperative');
    return true;
  }
  return false;
}
