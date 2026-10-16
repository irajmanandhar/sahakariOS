import { useSyncExternalStore } from 'react';

const noop = () => () => {};

/**
 * false during server render and before hydration, true once React runs in
 * the browser. Forms keep their submit button disabled until then: before
 * hydration a click (or Enter) would do a native HTML submit and could put
 * credentials or ID numbers into the URL.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(noop, () => true, () => false);
}
