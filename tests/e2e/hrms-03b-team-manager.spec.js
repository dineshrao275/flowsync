import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession, apiCall } from './helpers-workforce';

test.setTimeout(120_000);

// Data is created through the real Reporting line form on the employee page.
async function employeeId(page, name) {
    const list = await apiCall(page, 'GET', `/hrms/employees?q=${encodeURIComponent(name)}&per_page=20`);
    return list.json.employees.find((e) => (e.display_name ?? e.name) === name).id;
}

async function setManagerViaUi(page, subject, manager) {
    await page.goto('/app/hrms');
    await page.waitForLoadState('networkidle');
    const id = await employeeId(page, subject);
    await page.goto(`/app/hrms/employees/${id}`);
    await expect(page.getByText('Reporting line')).toBeVisible({ timeout: 30_000 });
    const select = page.locator('select').filter({ has: page.locator('option', { hasText: 'No manager' }) }).first();
    await select.scrollIntoViewIfNeeded();
    await select.selectOption({ label: manager });
    const save = page.getByRole('button', { name: 'Save', exact: true });
    // Save is disabled when nothing changed (the manager is already this person): nothing to submit.
    if (await save.isEnabled()) await save.click();
}

test.describe.serial('HRMS tab 3 — My team with real reports', () => {
    test('admin assigns QA HR Executive as QA Employee\'s manager through the Reporting line form', async ({ page }) => {
        await useSession(page);
        await setManagerViaUi(page, 'QA Employee', 'No manager');
        await setManagerViaUi(page, 'QA Employee', 'QA HR Executive');
        await page.waitForLoadState('networkidle');
        await page.reload();
        await expect(page.locator('select option:checked', { hasText: 'QA HR Executive' })).toHaveCount(1, { timeout: 30_000 });
    });

    test('manager: My team lists the direct report and the Apply window control works', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        const bad = [];
        page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) bad.push(`${r.status()} ${r.url()}`); });
        await page.goto('/app/hrms/team');
        await expect(page.getByText(/[1-9]\d* direct reports?/)).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText('QA Employee').first()).toBeVisible();
        await page.getByRole('button', { name: 'Apply window' }).click();
        await page.waitForLoadState('networkidle');
        expect(bad).toEqual([]);
    });

    test('report: the employee still has no direct reports of their own', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/team');
        await expect(page.getByText(/0 direct reports/)).toBeVisible({ timeout: 30_000 });
    });

    test('org chart shows the report under the manager (integration)', async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/org-chart');
        await expect(page.getByText('QA Employee', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
        await expect(page.getByRole('button', { name: /^[−-]\s*[1-9]/ }).first()).toBeVisible();
    });

    test('validation: a reporting loop is refused (QA HR Executive cannot report to their own report)', async ({ page }) => {
        await useSession(page);
        await setManagerViaUi(page, 'QA HR Executive', 'QA Employee');
        await page.waitForLoadState('networkidle');
        await page.reload();
        // The loop was refused server-side, so the saved manager is still empty.
        await expect(page.locator('select option:checked', { hasText: 'No manager' })).toHaveCount(1, { timeout: 30_000 });
    });
});
