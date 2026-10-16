/** Read and immutably write nested values by dotted path ("kym.permanent_address.ward"). */
export function getPath(obj: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((o, k) => (o && typeof o === 'object' ? (o as Record<string, unknown>)[k] : undefined), obj);
}

export function setPath<T extends Record<string, unknown>>(obj: T, path: string, value: unknown): T {
  const [head, ...rest] = path.split('.');
  const current = (obj[head] ?? {}) as Record<string, unknown>;
  return { ...obj, [head]: rest.length ? setPath(current, rest.join('.'), value) : value };
}
