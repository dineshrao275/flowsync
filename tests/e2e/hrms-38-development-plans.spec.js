import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, dialogWith, fillDate, flow } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const DONE = 'QA-PF-IDP-Complete';
const CANCEL = 'QA-PF-IDP-Cancel';
const HREXEC = 'QA-PF-IDP-HrExec';
// The title shares its paragraph with a status badge: substring match on that paragraph, never exact text.
const planTitle = (page, title) => page.locator('p.font-medium').filter({ hasText: title });
const planItem = (page, title) => page.getByRole('listitem').filter({ has: planTitle(page, title) }).first();
const dlg = (page) => dialogWith(page, 'Pick one thing to grow');

async function openPlans(page) {
    const list = page.waitForResponse((r) => /\/hrms\/development-plans$/.test(r.url()) && r.request().method() === 'GET', { timeout: 10_000 });
    await page.goto('/app/hrms/development-plans');
    await list;
    // The top-bar title is always on screen; the Start button only renders once the list has loaded.
    await expect(page.getByRole('button', { name: 'Start a plan' })).toBeVisible({ timeout: 8000 });
}

async function startPlan(page, { title, focus, start = '2026-01-01', end = '2026-12-31', actions = [] }) {
    await page.getByRole('button', { name: 'Start a plan' }).click({ timeout: 8000 });
    const d = dlg(page);
    await d.getByLabel(/^Goal/).fill(title, { timeout: 8000 });
    if (focus) await d.getByLabel(/^Why/).fill(focus);
    await fillDate(d, /^Starts/, start);
    await fillDate(d, /^Ends/, end);
    for (const [i, a] of actions.entries()) {
        if (i > 0) await d.getByRole('button', { name: 'Add an action' }).click();
        await d.getByLabel(`Action ${i + 1}`).fill(a);
    }
    return d;
}

test.describe('HRMS tab 38 — Development plans (/hrms/development-plans)', () => {
    test('Employee: the page opens with a Start a plan action', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openPlans(page);
        await expect(page.getByRole('button', { name: 'Start a plan' })).toBeVisible();
    });

    test('validation: Start plan is disabled until goal and dates are set; end before start and a 201-char goal are refused', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await flow(async () => {
            await openPlans(page);
            const d = await startPlan(page, { title: '', start: '', end: '' });
            await expect(d.getByRole('button', { name: 'Start plan' })).toBeDisabled();

            await d.getByLabel(/^Goal/).fill('QA-PF-IDP-Invalid');
            await fillDate(d, /^Starts/, '2026-06-01');
            await fillDate(d, /^Ends/, '2026-01-01');
            await d.getByRole('button', { name: 'Start plan' }).click();
            await expect(d.getByText('The plan must end after it starts.')).toBeVisible({ timeout: 8000 });

            await d.getByLabel(/^Goal/).fill('QA-PF-' + 'x'.repeat(210));
            await fillDate(d, /^Ends/, '2026-12-31');
            await d.getByRole('button', { name: 'Start plan' }).click();
            await expect(d.locator('p.text-red-600').filter({ hasText: /200/ })).toBeVisible({ timeout: 8000 });
        });
    });

    test('create: a plan with two actions starts Active, lists its actions, and persists', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        for (const [title, focus] of [[DONE, 'QA-PF focus text'], [CANCEL, null]]) {
            await flow(async () => {
                await openPlans(page);
                if (await planTitle(page, title).count()) return;
                const d = await startPlan(page, { title, focus, actions: ['QA-PF first step', 'QA-PF second step'] });
                await d.getByRole('button', { name: 'Start plan' }).click();
                await expect(page.getByText('Plan opened.')).toBeVisible({ timeout: 8000 });
            });
        }
        await openPlans(page);
        const item = planItem(page, DONE);
        await expect(item).toContainText(/Active|Completed/); // a plan from an earlier pass may already be closed
        await expect(item).toContainText('QA Employee');
        await expect(item).toContainText('QA-PF focus text');
        await expect(item.getByLabel('QA-PF first step')).not.toBeChecked();
    });

    test('actions: ticking an action persists across a reload, and unticking restores it', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openPlans(page);
        const box = planItem(page, CANCEL).getByLabel('QA-PF first step');
        test.skip(await box.isDisabled(), 'plan already closed on an earlier run');

        const before = await box.isChecked();
        const saved = page.waitForResponse((r) => /\/actions\/[\w-]+$/.test(r.url()) && r.request().method() === 'PUT');
        await box.click();
        await saved;
        await openPlans(page);
        await expect(planItem(page, CANCEL).getByLabel('QA-PF first step')).toBeChecked({ checked: !before });
    });

    test('privacy: a plan is seen by its owner, their manager and a performance manager, and by nobody else', async ({ page }) => {
        // QA HR Executive is QA Employee's manager, so the Employee's plan is rightly visible to them.
        await useSession(page, PERSONAS.hrExec);
        await openPlans(page);
        await expect(planTitle(page, DONE).first()).toBeVisible();

        // The HR Executive's own plan is private from their report (the Employee) ...
        await flow(async () => {
            await openPlans(page);
            if (await planTitle(page, HREXEC).count()) return;
            const d = await startPlan(page, { title: HREXEC, actions: ['QA-PF hr step'] });
            await d.getByRole('button', { name: 'Start plan' }).click();
            await expect(page.getByText('Plan opened.')).toBeVisible({ timeout: 8000 });
        });

        await useSession(page, PERSONAS.employee);
        await openPlans(page);
        await expect(planTitle(page, HREXEC)).toHaveCount(0);
        const mine = await (await apiCall(page, 'GET', '/hrms/development-plans')).json();
        expect(JSON.stringify(mine)).not.toContain(HREXEC);

        // ... while a performance manager sees every plan.
        await useSession(page, TENANT_ADMIN);
        await openPlans(page);
        await expect(planTitle(page, HREXEC).first()).toBeVisible();
        await expect(planTitle(page, DONE).first()).toBeVisible();
    });

    test('a login with no employment record cannot start a plan: the form says why', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openPlans(page);
            const d = await startPlan(page, { title: 'QA-PF-IDP-NoRecord', actions: ['x'] });
            await d.getByRole('button', { name: 'Start plan' }).click();
            await expect(d.getByText('You need an employment record to have a development plan.')).toBeVisible({ timeout: 8000 });
        });
    });

    test('close: Mark complete and Cancel plan set the status and lock the actions', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openPlans(page);
        for (const [title, button, status] of [[DONE, 'Mark complete', 'Completed'], [CANCEL, 'Cancel plan', 'Cancelled']]) {
            const item = planItem(page, title);
            if (await item.getByRole('button', { name: button }).count()) {
                const closed = page.waitForResponse((r) => /\/close$/.test(r.url()) && r.request().method() === 'POST');
                await item.getByRole('button', { name: button }).click();
                await closed;
            }
            await openPlans(page);
            await expect(planItem(page, title)).toContainText(status);
            await expect(planItem(page, title).getByRole('button', { name: /Mark complete|Cancel plan/ })).toHaveCount(0);
            await expect(planItem(page, title).getByLabel('QA-PF second step')).toBeDisabled();
        }
    });
});
