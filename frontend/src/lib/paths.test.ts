import { describe, expect, it } from 'vitest';
import { getPath, setPath } from './paths';

describe('paths', () => {
  it('sets nested values without mutating the original', () => {
    const before = { kym: { pep: { is_pep: false } } };
    const after = setPath(before, 'kym.permanent_address.ward', 5);

    expect(getPath(after, 'kym.permanent_address.ward')).toBe(5);
    expect(getPath(after, 'kym.pep.is_pep')).toBe(false);
    expect(getPath(before, 'kym.permanent_address')).toBeUndefined();
  });
});
