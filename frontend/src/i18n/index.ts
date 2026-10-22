import { useCallback, useEffect } from 'react';
import { type Locale, usePreferences } from '@/lib/preferences';
import { en } from './en';
import { ne } from './ne';

/**
 * Tiny typed dictionary: en.ts is the source of keys, ne.ts must provide every one
 * (the type checker refuses a missing key). Placeholders look like {name}.
 * API messages arrive in English and are shown as sent.
 */

type Widen<T> = { [K in keyof T]: T[K] extends string ? string : Widen<T[K]> };
export type Messages = Widen<typeof en>;
type Paths<T, P extends string = ''> = { [K in keyof T & string]: T[K] extends string ? `${P}${K}` : Paths<T[K], `${P}${K}.`> }[keyof T & string];
export type MessageKey = Paths<Messages>;
export type Vars = Record<string, string | number>;
export type T = (key: MessageKey, vars?: Vars) => string;

const dictionaries: Record<Locale, Messages> = { en, ne };

export function translate(locale: Locale, key: MessageKey, vars?: Vars): string {
  const lookup = (messages: Messages) => key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], messages);
  const text = (lookup(dictionaries[locale]) ?? lookup(en) ?? key) as string;
  return vars ? text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match)) : text;
}

export function useT(): T {
  const { locale } = usePreferences();
  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);
  return useCallback((key: MessageKey, vars?: Vars) => translate(locale, key, vars), [locale]);
}
