import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession, apiCall } from './helpers-workforce';

const RUN = Date.now().toString(36).slice(-5);
const LEAVER = `QA-WF-Leaver ${RUN}`;
const LEAVER2 = `QA-WF-Leaver2 ${RUN}`;

test.setTimeout(120_000);

async function openOffboarding(page) {
    await page.goto('/app/hrms/offboarding');
    await expect(page.getByText(/\d+ exits?/).first()).toBeVisible({ timeout: 30_000 });
}

test.describe.serial('HRMS tab 9 — Offboarding (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('read: the Status filter shows real labels, never translation keys', async ({ page }) => {
        await openOffboarding(page);
        for (const label of ['Initiated', 'In progress', 'Completed', 'Cancelled']) {
            await expect(page.getByRole('option', { name: label })).toHaveCount(1);
        }
        await expect(page.locator('option', { hasText: 'hrms.offboarding.' })).toHaveCount(0);
    });

    test('create: Open exit stays disabled until an employee and last working day are set', async ({ page }) => {
        await openOffboarding(page);
        await expect(page.getByRole('button', { name: 'Open exit' })).toBeDisabled();
        const made = await apiCall(page, 'POST', '/hrms/employees', { name: LEAVER, joining_date: '2026-01-05' });
        expect(made.status).toBe(201);
        await page.reload();
        await expect(page.getByText(/\d+ exits?/).first()).toBeVisible({ timeout: 30_000 });
        await page.getByRole('combobox').first().selectOption({ label: LEAVER });
        await expect(page.getByRole('button', { name: 'Open exit' })).toBeDisabled();
    });

    test('create: opens an exit, lists it Initiated with Open clearance, and persists', async ({ page }) => {
        await openOffboarding(page);
        await page.getByRole('combobox').first().selectOption({ label: LEAVER });
        await page.getByPlaceholder('Last working day').fill('2027-03-31');
        await page.getByPlaceholder('Notice (days)').fill('30');
        await page.getByRole('button', { name: 'Open exit' }).click();
        await page.waitForURL(/offboarding\/cases\/\d+/, { timeout: 30_000 });
        await expect(page.getByText(/Resigned · last day 2027-03-31 · Initiated/)).toBeVisible({ timeout: 30_000 });
        await openOffboarding(page);
        const row = page.getByRole('row').filter({ hasText: LEAVER });
        await expect(row).toContainText('2027-03-31');
        await expect(row).toContainText('Initiated');
        await expect(row).toContainText('Open');
    });

    test('validation: a second open exit for the same employee is refused', async ({ page }) => {
        await openOffboarding(page);
        await page.getByRole('combobox').first().selectOption({ label: LEAVER });
        await page.getByPlaceholder('Last working day').fill('2027-04-30');
        await page.getByRole('button', { name: 'Open exit' }).click();
        await expect(page.getByText(/already|open|exists|in progress/i).first()).toBeVisible({ timeout: 20_000 });
        await expect(page).toHaveURL(/\/hrms\/offboarding$/);
    });

    test('read: the Status filter narrows the list', async ({ page }) => {
        await openOffboarding(page);
        await page.getByRole('combobox').last().selectOption({ label: 'Completed' });
        await expect(page.getByRole('row').filter({ hasText: LEAVER })).toHaveCount(0, { timeout: 20_000 });
        await page.getByRole('combobox').last().selectOption({ label: 'Initiated' });
        await expect(page.getByRole('row').filter({ hasText: LEAVER })).toHaveCount(1, { timeout: 20_000 });
    });

    test('update: checklist items are marked Done and the exit is cleared and completed', async ({ page }) => {
        await openOffboarding(page);
        await page.getByRole('link', { name: LEAVER }).first().click();
        await page.waitForURL(/offboarding\/cases\/\d+/);
        await expect(page.getByText('Checklist')).toBeVisible({ timeout: 30_000 });
        const done = page.getByRole('button', { name: 'Done', exact: true });
        for (let n = await done.count(); n > 0; n -= 1) {
            await done.first().click();
            await expect(done).toHaveCount(n - 1, { timeout: 30_000 });
        }
        await page.getByRole('button', { name: 'Clear exit' }).click();
        await expect(page.getByText('Sign off exit?')).toBeVisible();
        await page.getByRole('button', { name: 'Confirm' }).click();
        await expect(page.getByText('Sign off exit?')).toHaveCount(0, { timeout: 30_000 });
        await page.getByRole('button', { name: 'Complete', exact: true }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /complete|finish|close/i }).last();
        if (await dlg.count() && await dlg.getByRole('button', { name: 'Confirm' }).count()) await dlg.getByRole('button', { name: 'Confirm' }).click();
        await expect(page.getByText(/· Completed/).first()).toBeVisible({ timeout: 30_000 });
    });

    // Valid rule: completing an exit signs off the paperwork only; employment status is changed separately.
    test('integration: completing the exit does not change the employment status', async ({ page }) => {
        await page.goto('/app/hrms/employees');
        await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
        await page.getByPlaceholder('Name or code').fill(LEAVER);
        const row = page.getByRole('row').filter({ hasText: LEAVER });
        await expect(row).toBeVisible({ timeout: 30_000 });
        await expect(row).toContainText('Active');
    });

    test('integration: terminating an employee flips the status and opens an exit case automatically; a second termination is refused', async ({ page }) => {
        await page.goto('/app/hrms');
        await page.waitForLoadState('networkidle');
        const made = await apiCall(page, 'POST', '/hrms/employees', { name: LEAVER2, joining_date: '2026-01-05' });
        expect(made.status).toBe(201);
        const id = made.json.employee.id;
        const first = await apiCall(page, 'POST', `/hrms/employees/${id}/terminate`, { reason: 'QA-WF: policy breach' });
        expect(first.status).toBe(200);
        const second = await apiCall(page, 'POST', `/hrms/employees/${id}/terminate`, { reason: 'again' });
        expect(second.status).toBe(422);

        await page.goto('/app/hrms/employees');
        await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
        await page.getByPlaceholder('Name or code').fill(LEAVER2);
        await expect(page.getByRole('row').filter({ hasText: LEAVER2 })).toContainText('Terminated', { timeout: 30_000 });

        await openOffboarding(page);
        const exit = page.getByRole('row').filter({ hasText: LEAVER2 });
        await expect(exit).toBeVisible({ timeout: 30_000 });
        await expect(exit).toContainText('Initiated');
    });

    test('cleanup: soft-delete the QA-WF-Leaver employees created by this run', async ({ page }) => {
        await page.goto('/app/hrms');
        await page.waitForLoadState('networkidle');
        const list = await apiCall(page, 'GET', '/hrms/employees?q=QA-WF-Leaver&per_page=50&status=');
        for (const e of list.json.employees) {
            const res = await apiCall(page, 'DELETE', `/hrms/employees/${e.id}?force=1`);
            expect([200, 403, 422]).toContain(res.status);
        }
    });
});

test.describe('HRMS tab 9 — Offboarding (roles)', () => {
    test('Employee persona is refused with the missing permission', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/offboarding');
        await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(/hrms\.offboarding\.view/)).toBeVisible();
    });

    test('Employee persona cannot open an exit through the API', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        await page.waitForLoadState('networkidle');
        const res = await apiCall(page, 'POST', '/hrms/offboarding/cases', { employee_id: 1, last_working_day: '2027-05-01', reason: 'resigned' });
        expect([403, 404]).toContain(res.status);
    });

    test('HR Executive can open the page', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openOffboarding(page);
    });
});
