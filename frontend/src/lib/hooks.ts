'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, api, redirectForAuthError } from './api';
import { newIdempotencyKey } from './idempotency';
import type { Page } from './types';

/**
 * GET a resource. 401 / 409 redirect (see redirectForAuthError); other errors are
 * returned for <ErrorState> to show with a retry. `path === null` waits (nothing fetched).
 */
export function useApi<T>(path: string | null): { data: T | undefined; error: unknown; loading: boolean; reload: () => void; setData: (d: T) => void } {
  const router = useRouter();
  const [nonce, setNonce] = useState(0);
  const request = path === null ? null : `${nonce}:${path}`;
  // `done` is the request the current data/error belong to; anything else is still loading.
  const [state, setState] = useState<{ done: string | null; data?: T; error?: unknown }>({ done: null });

  useEffect(() => {
    if (path === null || request === null) return;
    let live = true;
    api<{ data: T }>(path)
      .then((r) => live && setState({ done: request, data: r.data }))
      .catch((error) => {
        if (!live || redirectForAuthError(error, router.replace)) return;
        setState((s) => ({ done: request, data: s.data, error }));
      });
    return () => {
      live = false;
    };
  }, [path, request, router]);

  return {
    data: state.data,
    error: state.done === request ? state.error : undefined,
    loading: request !== null && state.done !== request,
    reload: useCallback(() => setNonce((n) => n + 1), []),
    setData: useCallback((data: T) => setState((s) => ({ ...s, data })), []),
  };
}

/** Cursor-paginated list ("Load more"). Changing `path` (filters) starts again from the first page. */
export function useCursorList<T>(path: string | null) {
  const router = useRouter();
  const [rows, setRows] = useState<T[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(path !== null);
  const [error, setError] = useState<unknown>();
  const generation = useRef(0);

  const fetchPage = useCallback(async (from: string | null, append: boolean) => {
    if (path === null) return;
    const mine = ++generation.current;
    setLoading(true);
    setError(undefined);
    try {
      const separator = path.includes('?') ? '&' : '?';
      const page = await api<Page<T>>(from ? `${path}${separator}cursor=${encodeURIComponent(from)}` : path);
      if (mine !== generation.current) return;
      setRows((r) => (append ? [...r, ...page.data] : page.data));
      setCursor(page.next_cursor ?? null);
    } catch (e) {
      if (mine === generation.current && !redirectForAuthError(e, router.replace)) setError(e);
    } finally {
      if (mine === generation.current) setLoading(false);
    }
  }, [path, router]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reset the list when the filters change
    setRows([]);
    fetchPage(null, false);
  }, [fetchPage]);

  return {
    rows,
    loading,
    error,
    hasMore: cursor !== null,
    loadMore: () => fetchPage(cursor, true),
    reload: () => fetchPage(null, false),
  };
}

type SubmitContext = { idempotencyKey: string };

/**
 * One form action. Blocks double submits, keeps Laravel's 422 field errors, and holds one
 * Idempotency-Key per attempt: a retry after a network failure or 5xx (outcome unknown) reuses it,
 * so the server posts at most once; a success or a definite refusal (4xx) starts a new key.
 */
export function useSubmit<A extends unknown[], R>(prefix: string, action: (ctx: SubmitContext, ...args: A) => Promise<R>) {
  const router = useRouter();
  const key = useRef<string | null>(null);
  const busy = useRef(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<unknown>();
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  /** Runs the action and rethrows failures (for ConfirmDialog, which shows them itself). */
  const attempt = useCallback(async (...args: A): Promise<R> => {
    if (busy.current) throw new Error('busy');
    busy.current = true;
    key.current ??= newIdempotencyKey(prefix);
    setSubmitting(true);
    setError(undefined);
    setFieldErrors({});
    try {
      const result = await action({ idempotencyKey: key.current }, ...args);
      key.current = null;
      return result;
    } catch (e) {
      if (e instanceof ApiError && e.status >= 400 && e.status < 500) key.current = null;
      if (e instanceof ApiError) setFieldErrors(e.fieldErrors);
      throw e;
    } finally {
      busy.current = false;
      setSubmitting(false);
    }
  }, [action, prefix]);

  /** Runs the action; failures are kept in `error` / `fieldErrors` (401/409 redirect). */
  const submit = useCallback(async (...args: A): Promise<R | undefined> => {
    try {
      return await attempt(...args);
    } catch (e) {
      if (!(e instanceof Error && e.message === 'busy') && !redirectForAuthError(e, router.replace)) setError(e);
      return undefined;
    }
  }, [attempt, router]);

  return { submit, attempt, submitting, error, fieldErrors, clearError: () => setError(undefined) };
}

/** Field error props for a TextField from Laravel's 422 errors. */
export function fieldError(errors: Record<string, string[]>, name: string): { error: boolean; helperText?: string } {
  return errors[name] ? { error: true, helperText: errors[name][0] } : { error: false };
}

const dirtyForms = new Set<symbol>();

/** True while any form on the page has unsaved changes (checked by in-app navigation). */
export function hasUnsavedChanges(): boolean {
  return dirtyForms.size > 0;
}

/** Warns before leaving the page (reload, close, in-app navigation) while `dirty`. */
export function useUnsavedChanges(dirty: boolean): void {
  useEffect(() => {
    if (!dirty) return;
    const id = Symbol('form');
    dirtyForms.add(id);
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => {
      dirtyForms.delete(id);
      window.removeEventListener('beforeunload', warn);
    };
  }, [dirty]);
}
