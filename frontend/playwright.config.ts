import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests against a running local stack (docker compose up; db:seed done).
 * They write synthetic data into the local development database. Never point them at real data.
 *   E2E_BASE_URL  default http://localhost:3000
 *   PW_CHANNEL    e.g. "chrome" to use the installed Chrome instead of Playwright's Chromium
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:3000',
    channel: process.env.PW_CHANNEL || undefined,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], channel: process.env.PW_CHANNEL || undefined, viewport: { width: 1440, height: 900 } } },
  ],
});
