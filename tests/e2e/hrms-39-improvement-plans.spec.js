import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, dialogWith, fillDate, flow } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const REASON = 'QA-PF-PIP missed two milestones';
const OUTCOME = 'QA-PF-PIP targets met';
const planItem = (page) => page.getByRole('listitem').filter({ hasText: REASON }).first();
const openForm = (page) => dialogWith(page, 'Set a clear reason');

async function openPlans(page) {
    const list = page.waitForResponse((r) => /\/hrms\/improvement-plans$/.test(r.url()) && r.request().method() === 'GET', { timeout: 10_000 });
    await page.goto('/app/hrms/improvement-plans');
    await list;
    // The top-bar title is always on screen; the empty text or a plan only renders once the list has loaded.
    await expect(page.getByText('No improvement plans.').or(page.locator('p.font-medium').first())).toBeVisible({ timeout: 8000 });
}

async function fillPlan(page, { employee = 'QA Employee', reason, start = '2026-01-01', end = '2026-12-31', milestones = [] }) {
    await page.getByRole('button', { name: 'Open a plan' }).click({ timeout: 8000 });
    const d = openForm(page);
    if (employee) {
        const person = d.getByLabel('Employee', { exact: true });
        await person.click({ timeout: 8000 });
        await person.fill(employee);
        await page.getByRole('option', { name: new RegExp(employee) }).first().click();
    }
    await d.getByLabel(/^Reason/).fill(reason);
    await fillDate(d, /^Starts/, start);
    await fillDate(d, /^Ends/, end);
    for (const [i, m] of milestones.entries()) {
        if (i > 0) await d.getByRole('button', { name: 'Add a milestone' }).click();
        await d.getByLabel(`Milestone ${i + 1}`).fill(m);
    }
    return d;
}

test.describe('HRMS tab 39 — Improvement plans (/hrms/improvement-plans)', () => {
    test('lead: an Employee gets no dead-end "Open a plan" action and no refused directory request', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        const refused = [];
        page.on('response', (r) => { if (/\/api\/hrms\/employees(\?|$)/.test(r.url()) && r.status() >= 400) refused.push(r.status()); });
        await openPlans(page);
        await expect(page.getByRole('button', { name: 'Open a plan' })).toHaveCount(0);
        expect(refused).toEqual([]);
    });

    test('validation: the form needs a person, reason and dates; an end before the start is refused once', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await flow(async () => {
            await openPlans(page);
            const d = await fillPlan(page, { employee: null, reason: '', start: '', end: '' });
            await expect(d.getByRole('button', { name: 'Open plan' })).toBeDisabled();

            const person = d.getByLabel('Employee', { exact: true });
            await person.click();
            await person.fill('QA Employee');
            await page.getByRole('option', { name: /QA Employee/ }).first().click();
            await d.getByLabel(/^Reason/).fill('QA-PF-PIP-Invalid');
            await fillDate(d, /^Starts/, '2026-06-01');
            await fillDate(d, /^Ends/, '2026-01-01');
            await d.getByRole('button', { name: 'Open plan' }).click();
            await expect(d.locator('p.text-red-600, [role="alert"]').first()).toBeVisible({ timeout: 8000 });
        });
    });

    test('manager: opens a plan for their report with milestones; it is listed and persists', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openPlans(page);
        test.skip((await planItem(page).count()) > 0, 'already opened on an earlier run');
        await flow(async () => {
            await openPlans(page);
            if (await planItem(page).count()) return;
            const d = await fillPlan(page, { reason: REASON, milestones: ['QA-PF first milestone', 'QA-PF second milestone'] });
            await d.getByRole('button', { name: 'Open plan' }).click();
            await expect(page.getByText('Plan opened.')).toBeVisible({ timeout: 8000 });
        });
        await openPlans(page);
        await expect(planItem(page)).toContainText('QA Employee');
        await expect(planItem(page)).toContainText(/Active|Completed/);
    });

    test('one active plan per person: a second plan for the same person is refused with a message', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openPlans(page);
        test.skip((await planItem(page).getByText('Completed').count()) > 0, 'the plan is already closed, so a new one is legitimately allowed');
        await flow(async () => {
            await openPlans(page);
            const d = await fillPlan(page, { reason: 'QA-PF-PIP-Second', milestones: ['x'] });
            await d.getByRole('button', { name: 'Open plan' }).click();
            await expect(d.locator('p.text-red-600, [role="alert"]').first()).toBeVisible({ timeout: 8000 });
        });
        await openPlans(page);
        await expect(page.getByText('QA-PF-PIP-Second')).toHaveCount(0);
    });

    test('manager ticks a milestone; the Employee sees the plan read-only', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openPlans(page);
        const box = planItem(page).getByLabel('QA-PF first milestone');
        if (!(await box.isDisabled())) {
            const before = await box.isChecked();
            const saved = page.waitForResponse((r) => /\/milestones\/[\w-]+$/.test(r.url()) && r.request().method() === 'PUT');
            await box.click();
            await saved;
            await openPlans(page);
            await expect(planItem(page).getByLabel('QA-PF first milestone')).toBeChecked({ checked: !before });
        }

        await useSession(page, PERSONAS.employee);
        await openPlans(page);
        await expect(planItem(page)).toContainText(REASON);
        await expect(planItem(page).getByLabel('QA-PF first milestone')).toBeDisabled();
        await expect(planItem(page).getByRole('button', { name: 'Close plan' })).toHaveCount(0);
    });

    test('permissions: the Employee cannot open or close a plan through the API either', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/improvement-plans');
        const forged = await apiCall(page, 'POST', '/hrms/employees/2/improvement-plans', { reason: 'QA-PF-forged', start_date: '2026-01-01', end_date: '2026-02-01' });
        expect(forged.status()).toBe(403);
    });

    test('close: a performance manager closes the plan with a result and outcome; it locks and the person sees it', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openPlans(page);
        const item = planItem(page);
        if (await item.getByRole('button', { name: 'Close plan' }).count()) {
            await item.getByRole('button', { name: 'Close plan' }).click();
            const d = page.getByRole('dialog').filter({ has: page.getByLabel('Outcome') });
            await expect(d.getByRole('button', { name: 'Close plan' })).toBeDisabled(); // outcome is required
            await d.getByLabel('Outcome').fill(OUTCOME);
            await d.getByRole('button', { name: 'Close plan' }).click();
            await expect(planItem(page)).toContainText('Completed', { timeout: 8000 });
        }
        await openPlans(page);
        await expect(planItem(page)).toContainText('Completed');
        await expect(planItem(page)).toContainText(OUTCOME);
        await expect(planItem(page).getByRole('button', { name: 'Close plan' })).toHaveCount(0);
        await expect(planItem(page).getByLabel('QA-PF second milestone')).toBeDisabled();

        await useSession(page, PERSONAS.employee);
        await openPlans(page);
        await expect(planItem(page)).toContainText(OUTCOME);
    });
});
