import fs from 'node:fs';
import { expect } from '@playwright/test';
import { useSession as baseUseSession } from './helpers';

// `helpers.js` reuses saved cookies without checking them. After an environment reset or another
// sign-in they can be dead and every page then lands on the login screen, so this validates the
// session first and signs in again (once) when the server rejects it.
export async function useSession(page, persona) {
    await baseUseSession(page, persona);
    const alive = async () => (await page.request.get('/api/auth/me', { headers: { Accept: 'application/json' } })).status() === 200;
    if (await alive()) return;
    fs.rmSync(`tests/e2e/.auth/${persona.email}.json`, { force: true });
    await page.context().clearCookies();
    await baseUseSession(page, persona);
}

// Direct API call using the page's session + CSRF cookie. Used ONLY to assert that the backend
// refuses what the UI hides; the behaviour under test itself is always driven through the UI.
export async function apiCall(page, method, path, data) {
    const cookies = await page.context().cookies();
    const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    return page.request.fetch(`/api${path}`, {
        method,
        data,
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'Content-Type': 'application/json' },
    });
}

// The dev server hot-reloads the whole SPA whenever any file changes, and several agents edit the
// tree at once; a reload mid-flow drops an open modal. Flows are therefore retried as a unit and
// every flow must be safe to restart from its first step.
export async function flow(fn, timeout = 60_000) {
    await expect(async () => { await fn(); }).toPass({ timeout, intervals: [500, 1000, 2000] });
}

export const CYCLES = {
    goals: 'QA-PF-Cycle-Goals', // stays in goal setting: goals are drafted against it
    flow: 'QA-PF-Cycle-Flow', // advanced one stage at a time by the tab-25 spec
};

// NB: `getByRole('dialog').last()` is the always-mounted Theme Settings drawer, not the open modal.
export const dialogWith = (page, text) => page.getByRole('dialog').filter({ hasText: text });

// The date fields are a calendar popup that stays open after typing and covers the submit button;
// Tab dismisses it the way a keyboard user would.
export async function fillDate(scope, label, value) {
    const input = scope.getByLabel(label);
    await input.fill(value);
    await input.press('Tab');
}

export function cycleCard(page, name) {
    return page.getByRole('link', { name, exact: true }).locator('xpath=ancestor::div[contains(@class,"justify-between")][1]');
}

export async function createCycleViaUi(page, name, { start = '2026-01-01', end = '2026-12-31', anonymity } = {}) {
    await flow(async () => {
        await page.goto('/app/hrms/performance');
        await expect(page.getByRole('heading', { name: 'Performance' }).first()).toBeVisible({ timeout: 8000 });
        // The subtitle reads "—" until the list has loaded; deciding earlier would re-create the cycle.
        await expect(page.getByText(/^\d+ cycles?$/)).toBeVisible({ timeout: 8000 });
        if (await page.getByRole('link', { name, exact: true }).count()) return;
        await page.getByRole('button', { name: 'New cycle' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'New review cycle' });
        await dlg.getByLabel(/^Name/).fill(name, { timeout: 8000 });
        await fillDate(dlg, /^Period start/, start);
        await fillDate(dlg, /^Period end/, end);
        if (anonymity) await dlg.getByLabel(/anonymity/i).selectOption(anonymity);
        await dlg.getByRole('button', { name: 'Create cycle' }).click();
        await expect(page.getByRole('link', { name, exact: true })).toBeVisible({ timeout: 8000 });
    });
}

export const SURVEY = { template: 'QA-PF-Pulse', campaign: 'QA-PF-Campaign' };

// Opens /hrms/engagement and resolves only once BOTH lists have answered, so "does it exist yet?"
// is never decided against a half-loaded page (the cause of duplicate test data earlier).
export async function openEngagement(page) {
    const lists = Promise.all([
        page.waitForResponse((r) => /\/hrms\/engagement\/templates$/.test(r.url()) && r.request().method() === 'GET', { timeout: 10_000 }),
        page.waitForResponse((r) => /\/hrms\/engagement\/campaigns$/.test(r.url()) && r.request().method() === 'GET', { timeout: 10_000 }),
    ]);
    await page.goto('/app/hrms/engagement');
    await lists;
    await expect(page.getByRole('heading', { name: 'Engagement' }).first()).toBeVisible({ timeout: 8000 });
    await page.waitForTimeout(300);
}

export const REQ = { template: 'QA-PF-Required', campaign: 'QA-PF-Campaign-Required', q1: 'QA-PF Rate us', q2: 'QA-PF Any comment?' };

// Admin-side setup through the real forms: a survey whose FIRST question is required, opened and
// invited. Every step first checks what already exists, so reruns add nothing.
export async function ensureRequiredSurvey(page) {
    await flow(async () => {
        await openEngagement(page);
        if (await page.getByText(REQ.template, { exact: true }).count()) return;
        await page.getByRole('button', { name: 'New template' }).click({ timeout: 8000 });
        const dlg = dialogWith(page, 'Create template');
        await dlg.getByLabel(/^Name/).fill(REQ.template, { timeout: 8000 });
        await dlg.getByLabel(/^Question 1/).fill(REQ.q1);
        await dlg.getByLabel(/^Required/).first().check();
        await dlg.getByRole('button', { name: 'Add a question' }).click();
        await dlg.getByLabel(/^Question 2/).fill(REQ.q2);
        await dlg.getByLabel(/^Type/).nth(2).selectOption('text');
        await dlg.getByRole('button', { name: 'Create template' }).click();
        await expect(page.getByText(REQ.template, { exact: true }).first()).toBeVisible({ timeout: 8000 });
    });
    await flow(async () => {
        await openEngagement(page);
        if (!(await page.getByText(REQ.campaign, { exact: true }).count())) {
            await page.getByRole('button', { name: 'Schedule a campaign' }).click({ timeout: 8000 });
            const dlg = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: 'Schedule', exact: true }) });
            await dlg.getByLabel(/^Template/).selectOption({ label: REQ.template }, { timeout: 8000 });
            await dlg.getByLabel(/^Name/).fill(REQ.campaign);
            await fillDate(dlg, /^Starts at/, '2026-01-01');
            await fillDate(dlg, /^Ends at/, '2026-12-31');
            await dlg.getByRole('button', { name: 'Schedule', exact: true }).click();
            await expect(page.getByText(REQ.campaign, { exact: true })).toBeVisible({ timeout: 8000 });
        }
        const row = page.getByRole('row').filter({ hasText: REQ.campaign });
        if (await row.getByRole('button', { name: 'Open', exact: true }).count()) {
            await row.getByRole('button', { name: 'Open', exact: true }).click();
            await expect(row.getByRole('button', { name: 'Invite' })).toBeVisible({ timeout: 8000 });
            await row.getByRole('button', { name: 'Invite' }).click();
            await expect(page.getByText('Audience invited.')).toBeVisible({ timeout: 8000 });
        }
    });
}

// Admin-side setup through the real UI: the Flow cycle goes check-in -> self review -> manager
// review, a review is filed for the QA Employee on the cycle detail page, then it opens calibration.
// Each step reads the current stage first so reruns and partial runs simply continue.
export async function ensureCalibrationRoom(page, employeeName = 'QA Employee') {
    const NEXT = ['Open self review', 'Open manager review', 'Open calibration'];
    const advance = async (label) => {
        await flow(async () => {
            await page.goto('/app/hrms/performance');
            await expect(page.getByText(/^\d+ cycles?$/)).toBeVisible({ timeout: 8000 });
            const card = cycleCard(page, CYCLES.flow);
            if (!(await card.getByRole('button', { name: label, exact: true }).count())) return;
            await card.getByRole('button', { name: label, exact: true }).click();
            await dialogWith(page, `${label}?`).getByRole('button', { name: label, exact: true }).click();
            await expect(card.getByRole('button', { name: label, exact: true })).toHaveCount(0, { timeout: 8000 });
        });
    };

    await advance(NEXT[0]);
    await advance(NEXT[1]);

    // File the review while the cycle is in manager review (a later stage may refuse it).
    await flow(async () => {
        await page.goto('/app/hrms/performance');
        await expect(page.getByText(/^\d+ cycles?$/)).toBeVisible({ timeout: 8000 });
        if (!(await cycleCard(page, CYCLES.flow).getByRole('button', { name: NEXT[2], exact: true }).count())) return; // already past manager review
        await page.getByRole('link', { name: CYCLES.flow, exact: true }).click();
        await page.getByRole('button', { name: 'Reviews', exact: true }).click({ timeout: 8000 });
        const mine = page.locator('p.font-medium', { hasText: employeeName });
        await page.waitForTimeout(500);
        if (await mine.count()) return;
        await page.getByRole('button', { name: 'File a review' }).click({ timeout: 8000 });
        const dlg = dialogWith(page, 'File a review (hidden)');
        const person = dlg.getByLabel(/^Person/);
        await person.click({ timeout: 8000 });
        await person.fill(employeeName);
        await page.getByRole('option', { name: new RegExp(employeeName) }).first().click({ timeout: 8000 });
        await dlg.getByLabel(/^Manager rating/).fill('3');
        await dlg.getByLabel(/^Strengths/).fill('QA-PF strengths');
        await dlg.getByRole('button', { name: 'File hidden' }).click();
        await expect(page.locator('p.font-medium', { hasText: employeeName }).first()).toBeVisible({ timeout: 8000 });
    });
    await advance(NEXT[2]);
}
