import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, dialogWith, fillDate, flow } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const COMPANY = 'QA-PF-OKR-Company';
const MINE = 'QA-PF-OKR-Mine';
// The title shares its paragraph with a scope badge, so match by substring on that paragraph, never exact text.
const okrTitle = (page, title) => page.locator('p.font-medium').filter({ hasText: title });

async function openOkrs(page) {
    const list = page.waitForResponse((r) => /\/hrms\/okrs$/.test(r.url()) && r.request().method() === 'GET', { timeout: 10_000 });
    await page.goto('/app/hrms/okrs');
    await list;
    // The top-bar title is always on screen; the New objective button only renders once the list has loaded.
    await expect(page.getByRole('button', { name: 'New objective' })).toBeVisible({ timeout: 8000 });
}
const form = (page) => dialogWith(page, 'Create objective');

async function fillObjective(page, { scope, title, period = 'QA-PF 2026', start = '2026-01-01', end = '2026-12-31', krs = [] }) {
    await page.getByRole('button', { name: 'New objective' }).click({ timeout: 8000 });
    const dlg = form(page);
    await dlg.getByLabel('Whose objective').selectOption(scope, { timeout: 8000 });
    await dlg.getByLabel(/^Objective/).fill(title);
    await dlg.getByLabel(/^Period/).fill(period);
    await fillDate(dlg, /^Starts/, start);
    await fillDate(dlg, /^Ends/, end);
    for (const [i, kr] of krs.entries()) {
        if (i > 0) await dlg.getByRole('button', { name: 'Add a key result' }).click();
        await dlg.getByLabel(`Key result ${i + 1}`).fill(kr.title);
        await dlg.getByLabel('Start value').nth(i).fill(kr.from ?? '0');
        await dlg.getByLabel('Target').nth(i).fill(kr.to);
    }
    return dlg;
}

test.describe('HRMS tab 37 — OKRs (/hrms/okrs)', () => {
    test('admin: page loads with the New objective action', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openOkrs(page);
        await expect(page.getByRole('button', { name: 'New objective' })).toBeVisible();
    });

    test('validation: Create stays disabled until title, period and dates are filled; end must be after start', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openOkrs(page);
            const dlg = await fillObjective(page, { scope: 'company', title: '', period: '' });
            await expect(dlg.getByRole('button', { name: 'Create objective' })).toBeDisabled();

            await dlg.getByLabel(/^Objective/).fill('QA-PF-OKR-Invalid');
            await dlg.getByLabel(/^Period/).fill('QA-PF');
            await fillDate(dlg, /^Starts/, '2026-06-01');
            await fillDate(dlg, /^Ends/, '2026-01-01');
            await dlg.getByRole('button', { name: 'Create objective' }).click();
            await expect(dlg.getByText(/after/i).first()).toBeVisible({ timeout: 8000 });
        });
    });

    test('validation: an objective title over 200 characters is refused with a message', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openOkrs(page);
            const dlg = await fillObjective(page, { scope: 'company', title: 'QA-PF-' + 'x'.repeat(210) });
            await dlg.getByRole('button', { name: 'Create objective' }).click();
            await expect(dlg.locator('p.text-red-600').filter({ hasText: /200/ })).toBeVisible({ timeout: 8000 });
        });
    });

    test('create (admin, company-wide): objective with two key results is listed and persists', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openOkrs(page);
        test.skip((await okrTitle(page, COMPANY).count()) > 0, 'already created on an earlier run');
        await flow(async () => {
            await openOkrs(page);
            if (await okrTitle(page, COMPANY).count()) return;
            const dlg = await fillObjective(page, { scope: 'company', title: COMPANY, krs: [{ title: 'QA-PF Reduce churn', from: '10', to: '20' }, { title: 'QA-PF Grow signups', to: '100' }] });
            await dlg.getByRole('button', { name: 'Create objective' }).click();
            await expect(page.getByText('Objective created.')).toBeVisible({ timeout: 8000 });
        });
        await openOkrs(page);
        await expect(okrTitle(page, COMPANY).first()).toBeVisible();
        await expect(page.getByText('QA-PF Reduce churn').first()).toBeVisible();
    });

    test('progress is computed from the numbers: moving a key result updates its percentage', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openOkrs(page);
        // A duplicate objective with the same key results exists (an earlier run of mine; objectives cannot be deleted).
        const input = page.getByLabel('Current value for QA-PF Reduce churn').first();
        test.skip((await input.count()) === 0, 'objective already closed on an earlier run');
        // start 10 -> target 20. Always move to a value different from the stored one, so a rerun
        // still sends a change: 15 -> 50%, 16 -> 60%.
        const next = (await input.inputValue()) === '15' ? '16' : '15';
        const expected = next === '15' ? '50%' : '60%';
        const updated = page.waitForResponse((r) => /key-results\/\d+$/.test(r.url()) && r.request().method() === 'PUT');
        await input.fill(next);
        await input.blur();
        await updated;
        await openOkrs(page);
        await expect(page.getByRole('listitem').filter({ hasText: 'QA-PF Reduce churn' }).first()).toContainText(expected);
    });

    test('Employee: sees the company objective read-only, and cannot create a company-wide one', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openOkrs(page);
        await expect(okrTitle(page, COMPANY).first()).toBeVisible();
        await expect(page.getByLabel('Current value for QA-PF Reduce churn')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Close objective' })).toHaveCount(0);

        const forbidden = await apiCall(page, 'POST', '/hrms/okrs', { scope: 'company', title: 'QA-PF-Forged', period: 'x', start_date: '2026-01-01', end_date: '2026-02-01' });
        expect(forbidden.status()).toBe(403);
    });

    test('Employee: creates their own objective through the form', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openOkrs(page);
        test.skip((await okrTitle(page, MINE).count()) > 0, 'already created on an earlier run');
        await flow(async () => {
            await openOkrs(page);
            if (await okrTitle(page, MINE).count()) return;
            const dlg = await fillObjective(page, { scope: 'mine', title: MINE, krs: [{ title: 'QA-PF Ship feature', to: '5' }] });
            await dlg.getByRole('button', { name: 'Create objective' }).click();
            await expect(page.getByText('Objective created.')).toBeVisible({ timeout: 8000 });
        });
        await openOkrs(page);
        await expect(okrTitle(page, MINE).first()).toBeVisible();
    });

    test('Employee: choosing Company-wide in the form is refused with a message on screen', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await flow(async () => {
            await openOkrs(page);
            const dlg = await fillObjective(page, { scope: 'company', title: 'QA-PF-OKR-NotAllowed', krs: [{ title: 'k', to: '1' }] });
            await dlg.getByRole('button', { name: 'Create objective' }).click();
            await expect(dlg.getByRole('alert').or(dlg.getByText(/unauthorized|not allowed|permission|forbidden/i)).first()).toBeVisible({ timeout: 8000 });
        });
        await openOkrs(page);
        await expect(page.getByText('QA-PF-OKR-NotAllowed')).toHaveCount(0);
    });

    test('admin without an employment record cannot own a personal objective: told so in the form', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openOkrs(page);
            const dlg = await fillObjective(page, { scope: 'mine', title: 'QA-PF-OKR-NoRecord', krs: [{ title: 'k', to: '1' }] });
            await dlg.getByRole('button', { name: 'Create objective' }).click();
            await expect(dlg.getByText('You need an employment record to own an objective.')).toBeVisible({ timeout: 8000 });
        });
    });

    test('close: closing the Mine objective marks it Closed and removes the edit controls', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openOkrs(page);
        const closeBtn = page.getByRole('button', { name: 'Close objective' });
        if (await closeBtn.count()) {
            await closeBtn.first().click();
            await expect(page.getByText('Closed', { exact: true }).first()).toBeVisible({ timeout: 8000 });
        }
        await openOkrs(page);
        await expect(page.getByText('Closed', { exact: true }).first()).toBeVisible();
        await expect(page.getByLabel('Current value for QA-PF Ship feature')).toHaveCount(0);
    });
});
