import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession } from './helpers-workforce';

test.setTimeout(120_000);

async function openChart(page) {
    await page.goto('/app/hrms/org-chart');
    await expect(page.getByRole('heading', { name: 'Org chart' }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('QA Employee', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
}

test.describe('HRMS tab 42 — Org chart (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('renders the reporting tree with no failing API call or console error', async ({ page }) => {
        const bad = [];
        page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) bad.push(`${r.status()} ${r.url()}`); });
        page.on('console', (m) => { if (m.type() === 'error') bad.push(m.text().slice(0, 120)); });
        await openChart(page);
        await expect(page.getByText('Who reports to whom. Open a manager to see their team.')).toBeVisible();
        expect(bad).toEqual([]);
    });

    // Valid design: the filter dims non-matching people (opacity) so the reporting structure stays visible.
    test('"Find a person" dims non-matches and keeps matches at full strength', async ({ page }) => {
        await openChart(page);
        const card = (name) => page.getByText(name, { exact: true }).first().locator('xpath=ancestor::div[contains(@class,"rounded-lg")][1]');
        await page.getByPlaceholder('Find a person').fill('QA HR Executive');
        await expect(card('QA-IN Analytics Viewer')).toHaveClass(/opacity-40/, { timeout: 20_000 });
        await expect(card('QA HR Executive')).not.toHaveClass(/opacity-40/);
        await page.getByPlaceholder('Find a person').fill('');
        await expect(card('QA-IN Analytics Viewer')).not.toHaveClass(/opacity-40/, { timeout: 20_000 });
    });

    test('a manager node collapses and expands its team', async ({ page }) => {
        await openChart(page);
        const toggle = page.getByRole('button', { name: /^[−-]\s*\d+$|^[+]\s*\d+$/ }).first();
        await expect(toggle).toBeVisible();
        const reportVisible = page.getByText('QA Employee', { exact: true });
        await expect(reportVisible.first()).toBeVisible();
        await toggle.click();
        await expect(reportVisible).toHaveCount(0, { timeout: 20_000 });
        await toggle.click();
        await expect(reportVisible.first()).toBeVisible();
    });
});

test.describe('HRMS tab 42 — Org chart (roles)', () => {
    test('Employee persona is refused with the missing permission', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/org-chart');
        await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(/hrms\.employees\.view/)).toBeVisible();
    });

    test('Employee persona cannot call the org-chart API', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        await page.waitForLoadState('networkidle');
        const status = await page.evaluate(async () => (await fetch('/api/hrms/org-chart', { headers: { Accept: 'application/json' } })).status);
        expect(status).toBe(403);
    });

    test('HR Executive can open the chart', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openChart(page);
    });
});
