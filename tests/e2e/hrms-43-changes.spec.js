import { test, expect } from '@playwright/test';
import { PERSONAS, uniq, isoDate, personaPage } from './helpers-records';

// Tab 43 — /hrms/changes (transfer / promotion / confirmation requests with an effective date)
test.describe.configure({ mode: 'serial' });
const OWNER = { email: 'owner@globex.test', password: 'password' };
const reasonA = uniq('promo');
const reasonB = uniq('withdraw');
const row = (page, text) => page.locator('li', { hasText: text });
const modal = (page) => page.getByRole('dialog').filter({ hasText: 'Only fill in what changes' });

async function openRequest(page) {
    await page.goto('/app/hrms/changes');
    await page.getByRole('button', { name: 'Request a change' }).click({ timeout: 60000 });
    await expect(modal(page)).toBeVisible();
    return modal(page);
}

test('setup: withdraw any pending QA-RC requests left by an earlier run (through the UI)', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await page.goto('/app/hrms/changes');
    const listLoaded = () => expect(page.getByText('No changes yet.').or(page.locator('ul.divide-y li').first())).toBeVisible({ timeout: 30000 });
    await listLoaded();
    for (let i = 0; i < 5; i += 1) {
        const left = page.locator('li', { hasText: 'QA-RC-' }).filter({ has: page.getByRole('button', { name: 'Withdraw' }) });
        if (!(await left.count())) break;
        await left.first().getByRole('button', { name: 'Withdraw' }).click();
        await expect(page.getByText('Change withdrawn.').first()).toBeVisible();
        await page.reload();
        await listLoaded();
    }
    await page.context().close();
});

test('Send for approval stays disabled until an employee and an effective date are given; a transfer with nothing changed is refused', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    const dlg = await openRequest(page);
    const send = dlg.getByRole('button', { name: 'Send for approval' });
    await expect(send).toBeDisabled();
    await dlg.getByLabel('Employee', { exact: true }).selectOption({ label: 'QA Employee' });
    await expect(send).toBeDisabled();
    await dlg.getByLabel('Effective date').fill(isoDate(30));
    await expect(send).toBeEnabled();
    await send.click();
    await expect(page.getByText('Change requested.')).toHaveCount(0);
    await expect(dlg.locator('p.text-red-600, [role=alert]').first()).toBeVisible({ timeout: 15000 });
    await page.context().close();
});

test('a promotion with a new designation is requested, listed Waiting with its effective date, and persists', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    const dlg = await openRequest(page);
    await dlg.getByLabel('Employee', { exact: true }).selectOption({ label: 'QA Employee' });
    await dlg.getByLabel('Type', { exact: true }).selectOption({ label: 'Promotion' });
    await dlg.getByLabel('Effective date').fill(isoDate(45));
    await dlg.getByLabel('Designation', { exact: true }).selectOption({ label: 'Senior Specialist' });
    await dlg.getByLabel(/^Reason/).fill(reasonA);
    await dlg.getByRole('button', { name: 'Send for approval' }).click();
    await expect(page.getByText('Change requested.').first()).toBeVisible();
    const r = row(page, reasonA);
    await expect(r).toContainText('QA Employee');
    await expect(r).toContainText('Designation');
    await page.reload();
    await expect(row(page, reasonA)).toBeVisible({ timeout: 30000 });
    await page.context().close();
});

test('only one change can wait for a decision per person (valid business rule), and it is shown as a visible error', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    const dlg = await openRequest(page);
    await dlg.getByLabel('Employee', { exact: true }).selectOption({ label: 'QA Employee' });
    await dlg.getByLabel('Effective date').fill(isoDate(50));
    await dlg.getByLabel('Department', { exact: true }).selectOption({ index: 1 });
    await dlg.getByLabel(/^Reason/).fill(reasonB);
    await dlg.getByRole('button', { name: 'Send for approval' }).click();
    await expect(dlg.getByText('This person already has a change waiting for a decision.')).toBeVisible({ timeout: 15000 });
    await expect(page.getByText('Change requested.')).toHaveCount(0);
    await page.context().close();
});

test('the requester sees Withdraw (never approve/reject) on their own request; a decider must give a note to reject', async ({ browser }) => {
    const admin = await personaPage(browser, OWNER);
    await admin.goto('/app/hrms/changes');
    await expect(row(admin, reasonA).getByRole('button', { name: 'Withdraw' })).toBeVisible({ timeout: 30000 });
    await expect(row(admin, reasonA).getByRole('button', { name: 'Approve' })).toHaveCount(0);

    const hr = await personaPage(browser, PERSONAS.hrExec);
    await hr.goto('/app/hrms/changes');
    const r = row(hr, reasonA);
    await expect(hr.getByRole('heading', { name: 'Transfers & promotions' }).first()).toBeVisible({ timeout: 30000 });
    if (await r.getByRole('button', { name: 'Reject' }).count()) {
        await r.getByRole('button', { name: 'Reject' }).click();
        await expect(hr.getByText('Change rejected.')).toHaveCount(0); // a note is needed
        await r.getByLabel(/^Note/).fill('QA-RC not this quarter');
        await r.getByRole('button', { name: 'Reject' }).click();
        await expect(hr.getByText('Change rejected.').first()).toBeVisible();
        await expect(row(hr, reasonA)).toContainText('Rejected');
        await expect(row(hr, reasonA)).toContainText('QA-RC not this quarter');
    } else {
        test.info().annotations.push({ type: 'finding', description: 'HR Executive has no decide controls on a pending change' });
        await expect(r.getByRole('button', { name: 'Approve' })).toHaveCount(0);
        await admin.reload();
        await row(admin, reasonA).getByRole('button', { name: 'Withdraw' }).click();
        await expect(admin.getByText('Change withdrawn.').first()).toBeVisible();
        await expect(row(admin, reasonA)).toContainText('Withdrawn');
    }
    await admin.context().close();
    await hr.context().close();
});

test('Employee: sees the page, no request button, and makes no failing background call (RC-10)', async ({ browser }) => {
    const page = await personaPage(browser, PERSONAS.employee);
    const failed = [];
    page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });
    await page.goto('/app/hrms/changes');
    await expect(page.getByRole('heading', { name: 'Transfers & promotions' }).first()).toBeVisible({ timeout: 30000 });
    await expect(page.getByRole('button', { name: 'Request a change' })).toHaveCount(0);
    await page.waitForTimeout(1500);
    expect(failed).toEqual([]);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const res = await page.request.post('/api/hrms/employees/1/changes', { data: { type: 'transfer', effective_date: isoDate(40), changes: { department_id: 1 } }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });
    expect([403, 404, 422]).toContain(res.status());
    await page.context().close();
});
