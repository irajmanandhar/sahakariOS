import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import HealthStatus from './HealthStatus';

afterEach(cleanup);

describe('HealthStatus', () => {
  it('shows healthy state and each dependency check', () => {
    render(<HealthStatus health={{ status: 'ok', checks: { database: 'ok', redis: 'ok' } }} />);

    expect(screen.getByText('API is healthy')).toBeTruthy();
    expect(screen.getByText('database')).toBeTruthy();
    expect(screen.getByText('redis')).toBeTruthy();
  });

  it('shows unreachable state', () => {
    render(<HealthStatus health={{ status: 'unreachable', checks: {} }} />);

    expect(screen.getByText('API is unreachable')).toBeTruthy();
  });
});
