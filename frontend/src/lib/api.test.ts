import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError, destinationAfterLogin } from './api';

const json = (body: unknown, status = 200, headers: Record<string, string> = {}) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });

afterEach(() => {
  vi.unstubAllGlobals();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('api', () => {
  it('sends JSON same-origin with the decoded XSRF token', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D%3D';
    const fetchMock = vi.fn().mockResolvedValue(json({ data: 1 }));
    vi.stubGlobal('fetch', fetchMock);

    await api('/auth/login', { method: 'POST', body: { email: 'a@b.test' } });

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/auth/login');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers['X-XSRF-TOKEN']).toBe('abc==');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(init.body).toBe('{"email":"a@b.test"}');
  });

  it('turns validation errors into the first field message', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      json({ message: 'x', errors: { email: ['These credentials do not match our records.'] } }, 422),
    ));

    await expect(api('/auth/login', { method: 'POST', body: {} })).rejects.toMatchObject({
      status: 422,
      message: 'These credentials do not match our records.',
    });
  });

  it('exposes Retry-After on throttling', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ message: 'Too Many Attempts.' }, 429, { 'Retry-After': '42' })));

    const error = (await api('/auth/login').catch((e: unknown) => e)) as ApiError;

    expect(error).toBeInstanceOf(ApiError);
    expect(error.retryAfter).toBe(42);
  });

  it('returns undefined for 204', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 204 })));

    expect(await api('/auth/logout', { method: 'POST' })).toBeUndefined();
  });
});

describe('destinationAfterLogin', () => {
  const membership = (id: number) => ({ cooperative: { id, code: 'C', name: 'C', status: 'active' }, branch: { id, code: 'HO', name: 'HO' } });

  it('selects the only cooperative automatically', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(json({ data: [membership(7)] }))
      .mockResolvedValueOnce(json({ data: membership(7) }));
    vi.stubGlobal('fetch', fetchMock);

    expect(await destinationAfterLogin()).toBe('/');
    expect(fetchMock.mock.calls[1][1]).toMatchObject({ method: 'PUT', body: '{"cooperative_id":7}' });
  });

  it('asks the user to choose when there are several', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json({ data: [membership(1), membership(2)] })));

    expect(await destinationAfterLogin()).toBe('/select-cooperative');
  });
});

describe('api uploads and field errors', () => {
  it('sends FormData without forcing a JSON content type', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 1 } }), { status: 201 }));
    vi.stubGlobal('fetch', fetchMock);
    const form = new FormData();
    form.append('type', 'photo');

    await api('/members/1/documents', { form });

    const [, init] = fetchMock.mock.calls[0];
    expect(init.method).toBe('POST');
    expect(init.body).toBe(form);
    expect(init.headers['Content-Type']).toBeUndefined();
  });

  it('keeps Laravel field errors by path', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
      message: 'x', errors: { 'kym.mother_name': ['The mother name field is required.'] },
    }), { status: 422 })));

    const error = (await api('/members', { method: 'POST', body: {} }).catch((e: unknown) => e)) as ApiError;

    expect(error.fieldErrors['kym.mother_name']).toEqual(['The mother name field is required.']);
  });
});

describe('idempotency', () => {
  it('sends the Idempotency-Key header when given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: 1 }), { status: 201 }));
    vi.stubGlobal('fetch', fetchMock);

    await api('/members/1/shares', { method: 'POST', body: { units: 1 }, idempotencyKey: 'share-abc12345' });

    expect(fetchMock.mock.calls[0][1].headers['Idempotency-Key']).toBe('share-abc12345');
  });
});
