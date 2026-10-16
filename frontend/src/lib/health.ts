export type HealthResult = {
  status: 'ok' | 'fail' | 'unreachable';
  checks: Record<string, string>;
};

/**
 * Calls the Laravel health endpoint from the server side.
 * API_INTERNAL_URL is a server-only variable (no NEXT_PUBLIC_ prefix), so the
 * browser never learns the internal API address.
 */
export async function fetchHealth(
  baseUrl: string = process.env.API_INTERNAL_URL ?? 'http://localhost:8080',
): Promise<HealthResult> {
  try {
    const res = await fetch(`${baseUrl}/api/health`, {
      cache: 'no-store',
      signal: AbortSignal.timeout(3000),
    });
    const body = await res.json();

    return {
      status: res.ok && body.status === 'ok' ? 'ok' : 'fail',
      checks: body.checks ?? {},
    };
  } catch {
    return { status: 'unreachable', checks: {} };
  }
}
