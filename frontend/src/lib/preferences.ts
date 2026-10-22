import { useSyncExternalStore } from 'react';

/**
 * Per-browser display preferences (language, Nepali numerals). Convenience only:
 * stored in localStorage, wrapped in try/catch, and the app works without them.
 * The server render always uses the defaults.
 * ponytail: English flashes before a saved Nepali preference applies; move to a cookie read in the root layout if that matters.
 */
export type Locale = 'en' | 'ne';
export type Preferences = { locale: Locale; nepaliDigits: boolean };

const KEY = 'sahakarios-preferences';
const DEFAULTS: Preferences = { locale: 'en', nepaliDigits: false };
const listeners = new Set<() => void>();
let cached: { raw: string | null; value: Preferences } | null = null;
let memory: Preferences | null = null; // used when storage refuses writes

function read(): Preferences {
  if (memory) return memory;
  let raw: string | null = null;
  try {
    raw = window.localStorage.getItem(KEY);
  } catch {
    // storage blocked: defaults
  }
  if (cached?.raw !== raw) {
    let value = DEFAULTS;
    try {
      const parsed = raw ? JSON.parse(raw) : {};
      value = { locale: parsed.locale === 'ne' ? 'ne' : 'en', nepaliDigits: parsed.nepaliDigits === true };
    } catch {
      // malformed: defaults
    }
    cached = { raw, value };
  }
  return cached.value;
}

export function setPreferences(change: Partial<Preferences>): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify({ ...read(), ...change }));
  } catch {
    memory = { ...read(), ...change };
  }
  listeners.forEach((l) => l());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  window.addEventListener('storage', listener);
  return () => {
    listeners.delete(listener);
    window.removeEventListener('storage', listener);
  };
}

export function usePreferences(): Preferences {
  return useSyncExternalStore(subscribe, read, () => DEFAULTS);
}
