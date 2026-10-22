import { expect, type Page, test } from '@playwright/test';
import { APPROVER, choose, confirmDialog, daysAgo, PNG, signedInPage, signIn, STAFF } from './helpers';

/**
 * The counter and audit path end to end, with two users (maker ≠ checker):
 * sign in → cooperative → business day → apply member → approve (second user) → deposit
 * → loan apply/approve/disburse → repay → trial balance → audit log.
 *
 * Loans need ≥ 3 months of membership (NCRA Std 3/1.1), so the run first imports a few members with
 * a backdated membership date through Settings → Import (the pilot-migration path). All data is synthetic.
 */
test.describe.serial('teller and auditor flow', () => {
  const base = Number(String(Date.now()).slice(-6)) * 10; // unique member numbers per run
  let staff: Page;
  let approver: Page;
  let applicantId = '';
  let loanUrl = '';

  test.beforeAll(async ({ browser }) => {
    staff = await signedInPage(browser, STAFF);
    approver = await signedInPage(browser, APPROVER);
  });

  test('imports members with a backdated membership (pilot migration)', async () => {
    const rows = Array.from({ length: 6 }, (_, i) => `${base + i},E2E Imported ${base + i},1980-01-01,${i % 2 ? 'male' : 'female'},98000000${i},${daysAgo(400)},2000,DEMO-GEN,50000.00`);
    const csv = ['member_number,full_name,date_of_birth,gender,phone,membership_date,share_units,savings_product_code,savings_balance', ...rows].join('\n');
    await staff.goto('/settings/import');
    await staff.locator('input[type=file]').first().setInputFiles({ name: 'members.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await staff.getByRole('button', { name: 'Check (nothing is saved)' }).first().click();
    await expect(staff.getByText('Check passed. You can import now.')).toBeVisible({ timeout: 60_000 });
    await staff.getByRole('button', { name: 'Import', exact: true }).first().click();
    await confirmDialog(staff, 'Import');
    await expect(staff.getByText('Imported.', { exact: true })).toBeVisible({ timeout: 60_000 });
  });

  test('signs in, has a cooperative and an open business day', async ({ page }) => {
    await signIn(page, STAFF);
    const open = page.getByRole('button', { name: 'Open today’s business day' });
    if (await open.isVisible()) {
      await open.click();
      await confirmDialog(page, 'Open today’s business day');
    }
    await expect(page.getByText('Open for transactions')).toBeVisible();
  });

  test('applies for membership (Schedule-1, all steps)', async () => {
    const next = () => staff.getByRole('button', { name: 'Next', exact: true }).click();
    await staff.goto('/members/new');
    await staff.getByLabel('Full name (as on citizenship)').fill(`E2E Applicant ${base}`);
    await staff.getByRole('button', { name: 'Enter in AD' }).first().click();
    await staff.getByLabel('Date of birth (AD)').fill('1990-05-01');
    await choose(staff, /Gender/, 'Female');
    await next();
    await staff.getByLabel('Citizenship certificate no.').fill(`E2E-${base}`);
    await staff.getByLabel('Issuing district').fill('Kathmandu');
    await staff.getByLabel('Citizenship issue date (BS)').fill('2065-01-01');
    await next();
    await staff.getByLabel('Mother’s name').fill('Gita Example');
    await staff.getByLabel('Father’s name').fill('Hari Example');
    await choose(staff, /Marital status/, 'Unmarried');
    await choose(staff, /Family type/, 'Separated / nuclear');
    await next();
    await choose(staff, /Main occupation/, 'Agriculture');
    await next();
    await staff.getByLabel('Permanent: province').fill('Bagmati');
    await staff.getByLabel('Permanent: municipality / rural municipality').fill('Kathmandu');
    await staff.getByLabel('Permanent: ward').fill('5');
    await choose(staff, /Lives in the working area as/, 'Permanent resident');
    await next();
    await staff.getByLabel('Purpose of membership').fill('Savings and small loans');
    await next();
    await choose(staff, /Annual family income/, 'Up to Rs 4 lakh');
    await next();
    for (const label of [/I am a Nepali citizen/, /I reside in the cooperative/, /I am not a member of another cooperative/, /The details are true/]) {
      await staff.getByLabel(label).check();
    }
    await staff.getByRole('button', { name: 'Add', exact: true }).click();
    await staff.getByRole('button', { name: 'Add', exact: true }).click();
    await staff.getByLabel('Membership no.').nth(0).fill(String(base));
    await staff.getByLabel('Membership no.').nth(1).fill(String(base + 1));
    await next();
    await expect(staff.getByRole('heading', { name: 'Review' })).toBeVisible();
    await staff.getByRole('button', { name: 'Submit application' }).click();
    await staff.waitForURL(/\/members\/\d+$/);
    applicantId = staff.url().split('/').pop()!;
  });

  test('uploads the required documents and assesses risk', async () => {
    await staff.goto(`/members/${applicantId}?tab=documents`);
    for (const type of ['Photo', 'Right thumbprint', 'Left thumbprint', 'Citizenship certificate']) {
      await choose(staff, /Document type/, new RegExp(`^${type}$`));
      await staff.locator('input[type=file]').setInputFiles({ name: 'scan.png', mimeType: 'image/png', buffer: PNG });
      await staff.getByRole('button', { name: 'Upload', exact: true }).click();
      await expect(staff.getByText(`${type} uploaded.`)).toBeVisible();
    }
    await expect(staff.getByText('One ID copy: provided')).toBeVisible();
    await staff.getByRole('tab', { name: 'Risk and decision' }).click();
    await choose(staff, /Risk level/, 'Low risk');
    await staff.getByLabel(/^Reason/).fill('Farmer, small expected volumes');
    await staff.getByRole('button', { name: 'Save assessment' }).click();
    await expect(staff.getByText('Risk assessment saved.')).toBeVisible();
  });

  test('a second user approves the application', async () => {
    await approver.goto(`/members/${applicantId}?tab=compliance`);
    await approver.getByRole('button', { name: 'Approve' }).click();
    await confirmDialog(approver, 'Approve');
    await expect(approver.getByText(/Approved as member no\. \d+/)).toBeVisible();
  });

  test('opens a savings account and takes a cash deposit', async () => {
    await staff.goto(`/members/${applicantId}?tab=savings`);
    await choose(staff, /^Product/, /DEMO-GEN/);
    await staff.getByRole('button', { name: 'Open account' }).click();
    await confirmDialog(staff, 'Open account');
    await expect(staff.getByText('Account opened.')).toBeVisible();
    await staff.getByRole('table', { name: 'Savings' }).getByRole('link').first().click();
    await staff.waitForURL(/\/savings\/accounts\/\d+$/);
    await staff.getByLabel(/Amount \(Rs\)/).fill('5,000');
    await staff.getByRole('button', { name: 'Review deposit' }).click();
    await confirmDialog(staff, 'Post deposit');
    await expect(staff.getByRole('alert').filter({ hasText: 'Deposit posted.' })).toBeVisible();
    await expect(staff.getByRole('table', { name: 'Passbook' })).toContainText('5,000.00');
  });

  test('applies for, approves and disburses a savings-secured loan', async () => {
    const res = await staff.request.get('/api/members?status=active');
    const member = ((await res.json()).data as { id: number; member_number: number }[]).find((m) => m.member_number === base + 2);
    expect(member, 'imported member').toBeTruthy();
    await staff.goto(`/loans/new?member=${member!.id}`);
    await choose(staff, /^Product/, /DEMO-LS/);
    await staff.getByLabel(/Amount requested/).fill('40,000');
    await staff.getByLabel(/^Purpose/).fill('Buffalo');
    await choose(staff, /Pledged savings account/, /.+/);
    await staff.getByRole('button', { name: 'Review application' }).click();
    await confirmDialog(staff, 'Record application');
    await staff.waitForURL(/\/loans\/\d+$/);
    loanUrl = new URL(staff.url()).pathname;

    await approver.goto(loanUrl);
    await approver.getByRole('button', { name: 'Approve' }).click();
    await confirmDialog(approver, 'Approve');
    await expect(approver.getByText('Loan approved.')).toBeVisible();

    await staff.goto(loanUrl);
    await choose(staff, /Into savings account/, /.+/);
    await staff.getByRole('button', { name: 'Review disbursement' }).click();
    await confirmDialog(staff, 'Disburse');
    await expect(staff.getByText('Loan disbursed.')).toBeVisible();
    await expect(staff.getByRole('table', { name: 'Schedule' })).toBeVisible();
  });

  test('takes a loan repayment', async () => {
    await staff.goto(loanUrl);
    await staff.getByLabel(/Amount \(Rs\)/).fill('1,000');
    await staff.getByRole('button', { name: 'Review repayment' }).click();
    await confirmDialog(staff, 'Post repayment');
    await expect(staff.getByRole('alert').filter({ hasText: 'Repayment posted.' })).toBeVisible();
  });

  test('the trial balance balances', async () => {
    await staff.goto('/ledger');
    await expect(staff.getByText('Balanced', { exact: true })).toBeVisible();
  });

  test('the audit log shows the postings with an unbroken sequence', async () => {
    await staff.goto('/audit');
    await expect(staff.getByRole('table', { name: 'Audit log' })).toContainText('journal.posted');
    await expect(staff.getByText(/no gaps in the sequence/)).toBeVisible();
  });
});
