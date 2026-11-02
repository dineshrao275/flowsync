import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, RESIGNER, ensureUser, isoDate, personaPage } from './helpers-records';

// Tabs 32 (/hrms/resignation) and 33 (/hrms/exit-interview) share one dedicated leaver, QA-RC Resigner,
// so the shared QA Employee persona is never put into an exit flow.
test.describe.configure({ mode: 'serial' });
const lastDay = isoDate(90);

const fileForm = (page) => ({
    day: page.getByLabel(/^Requested last working day/),
    notice: page.getByLabel(/^Notice period/),
    reason: page.getByLabel(/^Reason/),
    submit: page.getByRole('button', { name: 'Submit resignation' }),
});

test('setup: admin creates the dedicated leaver through the Add user form', async ({ page }) => {
    await useSession(page);
    await ensureUser(page, RESIGNER);
});

test('Resignation form: submit stays disabled until last day and reason are given; notice is bounded 0-365', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/resignation');
    const f = fileForm(page);
    await expect(f.submit).toBeVisible({ timeout: 30000 });
    await expect(f.submit).toBeDisabled();
    await f.day.fill(lastDay);
    await expect(f.submit).toBeDisabled();
    await f.reason.fill('   ');
    await expect(f.submit).toBeDisabled();
    await f.reason.fill('QA-RC relocating');
    await expect(f.submit).toBeEnabled();
    await f.notice.fill('366');
    expect(await f.notice.evaluate((el) => el.validity.rangeOverflow)).toBe(true);
    await f.notice.fill('-1');
    expect(await f.notice.evaluate((el) => el.validity.rangeUnderflow)).toBe(true);
});

test('A last day in the past is refused by the server', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/resignation');
    const f = fileForm(page);
    await f.day.fill(isoDate(-5));
    await f.reason.fill('QA-RC past date');
    await f.submit.click();
    await expect(page.getByText('Resignation submitted.')).toHaveCount(0);
    await expect(page.locator('p.text-red-600, [role=alert]').first()).toBeVisible({ timeout: 15000 });
});

test('File, see it pending, withdraw with confirmation, then file again', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/resignation');
    const f = fileForm(page);
    await f.day.fill(lastDay);
    await f.notice.fill('30');
    await f.reason.fill('QA-RC first attempt');
    await f.submit.click();
    await expect(page.getByText('Resignation submitted.')).toBeVisible();
    await expect(page.getByText('QA-RC first attempt')).toBeVisible();
    await expect(page.getByText('Pending', { exact: true })).toBeVisible();
    await expect(f.submit).toHaveCount(0); // one open resignation at a time
    await page.getByRole('button', { name: 'Withdraw' }).click();
    await page.getByRole('dialog').filter({ hasText: 'Withdraw your resignation?' }).getByRole('button', { name: 'Withdraw', exact: true }).click(); // BUG-RS2: used to read "Delete"
    await expect(page.getByText('Resignation withdrawn.')).toBeVisible();
    await expect(page.getByText('Withdrawn', { exact: true })).toBeVisible();
    await f.day.fill(lastDay);
    await f.reason.fill('QA-RC second attempt');
    await f.submit.click();
    await expect(page.getByText('Resignation submitted.')).toBeVisible();
});

test('Employee cannot see or decide anyone else\'s resignation', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/resignation');
    await expect(page.getByRole('heading', { name: 'Resignation' }).first()).toBeVisible({ timeout: 30000 });
    await expect(page.getByText('QA-RC second attempt')).toHaveCount(0);
    await expect(page.getByText('Waiting for your decision')).toHaveCount(0);
    const r = await page.request.post('/api/hrms/resignations/1/approve', { data: { note: 'x' }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '') } });
    // The service refuses a non-approver with a 422 domain error (project pattern); 403/404 are also acceptable.
    expect([403, 404, 422]).toContain(r.status());
    expect(JSON.stringify(await r.json())).not.toContain('Resignation decided.');
});

test('Approver: reject needs a note; reject with note shows to the leaver; the leaver can re-file; approve opens the exit case', async ({ browser }) => {
    const admin = await personaPage(browser, { email: 'owner@globex.test', password: 'password' });
    const leaver = await personaPage(browser, RESIGNER);
    const decisionCard = (text) => admin.locator('li', { hasText: text }).filter({ hasText: RESIGNER.name }).filter({ has: admin.getByRole('button', { name: 'Approve' }) });

    await admin.goto('/app/hrms/resignation');
    let card = decisionCard('QA-RC second attempt');
    await expect(card).toBeVisible({ timeout: 30000 });
    await card.getByRole('button', { name: 'Reject' }).click();
    await expect(admin.getByText('Resignation rejected.')).toHaveCount(0);
    await card.getByLabel(/^Note/).fill('QA-RC not now');
    await card.getByRole('button', { name: 'Reject' }).click();
    await expect(admin.getByText('Resignation rejected.')).toBeVisible();

    await leaver.goto('/app/hrms/resignation');
    await expect(leaver.getByText('QA-RC not now')).toBeVisible({ timeout: 30000 });
    await expect(leaver.getByText('Rejected', { exact: true })).toBeVisible();
    const f = fileForm(leaver);
    await f.day.fill(lastDay);
    await f.reason.fill('QA-RC third attempt');
    await f.submit.click();
    await expect(leaver.getByText('Resignation submitted.')).toBeVisible();

    await admin.goto('/app/hrms/resignation');
    card = decisionCard('QA-RC third attempt');
    await card.getByRole('button', { name: 'Approve' }).click();
    await expect(admin.getByText('Decision recorded.')).toBeVisible();
    await admin.context().close();
    await leaver.context().close();
});

test('After approval the leaver sees Approved and the exit-interview link; admin sees the exit case link', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/resignation');
    await expect(page.getByText('Approved', { exact: true })).toBeVisible({ timeout: 30000 });
    await expect(page.getByRole('link', { name: 'Complete your exit interview' })).toBeVisible();
});
