import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, createCycleViaUi, flow, CYCLES } from './helpers-performance';

test.describe.configure({ mode: 'serial' });

const GOAL_SELF = 'QA-PF-Goal-Self';
const GOAL_OTHER = 'QA-PF-Goal-HrExec';

const draftForm = (page) => page.getByRole('dialog').filter({ has: page.getByLabel(/^Title/) });

async function draftGoal(page, title, { weight = '100', target } = {}) {
    await flow(async () => {
        // The page shows "No goals" until the caller's own record resolves, so that text is not a
        // reliable "empty" signal; only the employee-scoped goals response is.
        const settled = page.waitForResponse((r) => /\/cycles\/\d+\/goals\?employee_id=\d+/.test(r.url()), { timeout: 8000 });
        await page.goto('/app/hrms/performance/mine');
        await expect(page.getByRole('heading', { name: 'My performance' }).first()).toBeVisible({ timeout: 8000 });
        await settled;
        await page.getByRole('button', { name: CYCLES.goals, exact: true }).click({ timeout: 8000 });
        await page.waitForTimeout(300);
        if (await page.getByText(title, { exact: true }).count()) return;
        await page.getByRole('button', { name: 'Draft a goal' }).click();
        const dlg = draftForm(page);
        await dlg.getByLabel(/^Title/).fill(title, { timeout: 8000 });
        await dlg.getByLabel(/^Weight/).fill(weight);
        if (target) await dlg.getByLabel(/^Target/).fill(target);
        await dlg.getByRole('button', { name: 'Draft goal' }).click();
        await expect(page.getByText(title, { exact: true }).first()).toBeVisible({ timeout: 8000 });
    });
}

test.describe('HRMS tab 26 — My performance (/hrms/performance/mine)', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        await useSession(page, TENANT_ADMIN);
        await createCycleViaUi(page, CYCLES.goals);
        await createCycleViaUi(page, CYCLES.flow, { anonymity: 'reviewer' });
        await page.close();
    });

    test('Employee: the page opens (no 403) and lists the cycles as tabs', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/performance/mine');
        await expect(page.getByRole('heading', { name: 'My performance' }).first()).toBeVisible();
        await expect(page).toHaveURL(/\/hrms\/performance\/mine$/);
        await expect(page.getByRole('button', { name: CYCLES.goals, exact: true })).toBeVisible();
    });

    test('validation: a non-numeric or negative weight and a non-numeric target are refused', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await flow(async () => {
            await page.goto('/app/hrms/performance/mine');
            await page.getByRole('button', { name: CYCLES.goals, exact: true }).click({ timeout: 8000 });
            await page.getByRole('button', { name: 'Draft a goal' }).click();
            const dlg = draftForm(page);
            await dlg.getByLabel(/^Title/).fill('QA-PF-Invalid-Goal', { timeout: 8000 });
            await dlg.getByLabel(/^Weight/).fill('abc');
            await dlg.getByRole('button', { name: 'Draft goal' }).click();
            await expect(dlg.getByText(/number/i)).toBeVisible({ timeout: 8000 });

            await dlg.getByLabel(/^Weight/).fill('-5');
            await dlg.getByRole('button', { name: 'Draft goal' }).click();
            await expect(dlg.getByText(/at least 0|min/i)).toBeVisible({ timeout: 8000 });

            await dlg.getByLabel(/^Weight/).fill('10');
            await dlg.getByLabel(/^Target/).fill('lots');
            await dlg.getByRole('button', { name: 'Draft goal' }).click();
            await expect(dlg.getByText(/number/i)).toBeVisible({ timeout: 8000 });
        });
        await expect(page.getByText('QA-PF-Invalid-Goal')).toHaveCount(0);
    });

    test('create: a drafted goal shows under My goals and survives a reload', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await draftGoal(page, GOAL_SELF);
        await page.reload();
        await page.getByRole('button', { name: CYCLES.goals, exact: true }).click();
        await expect(page.getByText(GOAL_SELF, { exact: true }).first()).toBeVisible();
    });

    test('isolation: a colleague drafts a goal; the Employee never sees it, in the UI or the API', async ({ page, browser }) => {
        const other = await browser.newPage({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        await useSession(other, PERSONAS.hrExec);
        await draftGoal(other, GOAL_OTHER);
        const ownerEmployeeId = (await (await apiCall(other, 'GET', '/my/hr')).json()).profile.id;
        await other.close();

        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/performance/mine');
        await page.getByRole('button', { name: CYCLES.goals, exact: true }).click();
        await expect(page.getByText(GOAL_SELF, { exact: true }).first()).toBeVisible();
        await expect(page.getByText(GOAL_OTHER, { exact: true })).toHaveCount(0);

        const cycles = await (await apiCall(page, 'GET', '/hrms/performance/cycles')).json();
        const cycleId = cycles.cycles.find((c) => c.name === CYCLES.goals).id;
        const spoofed = await apiCall(page, 'GET', `/hrms/performance/cycles/${cycleId}/goals?employee_id=${ownerEmployeeId}`);
        expect((await spoofed.json()).goals).toEqual([]);

        const forged = await apiCall(page, 'POST', `/hrms/performance/cycles/${cycleId}/goals`, {
            employee_id: ownerEmployeeId, title: 'QA-PF-Forged', metric_type: 'manual', weight: '10',
        });
        expect(forged.status()).toBe(403);
    });

    test('valid restriction: a login with no employment record (the seeded owner) is told so instead of drafting', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await page.goto('/app/hrms/performance/mine');
        await page.getByRole('button', { name: CYCLES.goals, exact: true }).click();
        await page.getByRole('button', { name: 'Draft a goal' }).click();
        await expect(page.getByText('No employment record found for this login.')).toBeVisible();
    });

    test('Employee cannot reach the manager-only cycles page from here', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/performance');
        await expect(page.getByText('Access denied')).toBeVisible();
    });
});
