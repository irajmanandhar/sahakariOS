import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { signIn, STAFF } from './helpers';

/** WCAG 2.2 A/AA checks (axe) on the main screens, signed out and signed in, at desktop and phone width. */
const PAGES = ['/', '/members', '/members/new', '/savings', '/loans', '/ledger', '/ledger/journal', '/reports', '/reports/balance_sheet', '/audit', '/settings/import'];

async function check(page: import('@playwright/test').Page, path: string) {
  // nextjs-portal: the dev-server overlay (not part of the app)
  const results = await new AxeBuilder({ page }).exclude('nextjs-portal').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(serious.map((v) => `${path}: ${v.id} (${v.nodes.length}) ${v.nodes[0]?.html.slice(0, 160)} — ${v.nodes[0]?.failureSummary?.replace(/\s+/g, ' ').slice(0, 200)}`)).toEqual([]);
}

for (const width of [1440, 360]) {
  test.describe(`accessibility at ${width}px`, () => {
    // Reduced motion removes transitions, so colours are measured at rest.
    test.use({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });

    test('sign-in page', async ({ page }) => {
      await page.goto('/login');
      await expect(page.getByRole('button', { name: 'Sign in' })).toBeEnabled();
      // Emotion swaps the disabled styles a moment after hydration: measure the settled button.
      await expect(page.getByRole('button', { name: 'Sign in' })).not.toHaveCSS('background-color', 'rgba(0, 0, 0, 0.12)');
      await check(page, '/login');
    });

    test('staff pages', async ({ page }) => {
      await signIn(page, STAFF);
      for (const path of PAGES) {
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await check(page, path);
      }
    });
  });
}
