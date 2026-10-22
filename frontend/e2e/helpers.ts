import { type Browser, expect, type Page } from '@playwright/test';

export const PASSWORD = 'local-dev-password'; // local seed only (README)
export const STAFF = 'staff@demo.test';
export const APPROVER = 'approver@demo.test';

/** Signs in through the UI and lands on the dashboard (choosing the first cooperative if asked). */
export async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));
  if (new URL(page.url()).pathname === '/select-cooperative') {
    await page.getByRole('button').filter({ hasText: /\w/ }).first().click();
  }
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
}

export async function signedInPage(browser: Browser, email: string): Promise<Page> {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await signIn(page, email);
  return page;
}

/** Picks an option of an MUI select by its label. */
export async function choose(page: Page, label: string | RegExp, option: string | RegExp): Promise<void> {
  await page.getByRole('combobox', { name: label }).click();
  await page.getByRole('option', { name: option }).first().click();
}

/** Clicks the confirm button inside the open dialog. */
export async function confirmDialog(page: Page, button: string | RegExp): Promise<void> {
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: button }).click();
  await expect(dialog).toBeHidden();
}

/** A valid 1×1 PNG (the API sniffs content, not the file name). */
export const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');

/** AD date n days before today (UTC calendar date). */
export function daysAgo(n: number): string {
  return new Date(Date.now() - n * 86_400_000).toISOString().slice(0, 10);
}
