import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchHealth } from './health';

afterEach(() => vi.unstubAllGlobals());

describe('fetchHealth', () => {
  it('returns ok when API reports ok', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'ok', checks: { database: 'ok' } }), { status: 200 }),
    ));

    expect(await fetchHealth('http://api')).toEqual({ status: 'ok', checks: { database: 'ok' } });
  });

  it('returns fail on 503', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'fail', checks: { database: 'fail' } }), { status: 503 }),
    ));

    expect((await fetchHealth('http://api')).status).toBe('fail');
  });

  it('returns unreachable when the request throws', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('fetch failed')));

    expect(await fetchHealth('http://api')).toEqual({ status: 'unreachable', checks: {} });
  });
});
