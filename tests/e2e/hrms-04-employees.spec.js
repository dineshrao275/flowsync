import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession, apiCall } from './helpers-workforce';


const RUN = Date.now().toString(36).slice(-5);
const NAME = `QA-WF-Alice ${RUN}`;
const EDITED = 'QA Lead';
// A date picker opens its own role=dialog, so `.last()` is unstable: anchor on the modal's copy.
const dialog = (page) => page.getByRole('dialog').filter({ hasText: /optionally a sign-in account|Edit employee|Edit details/ }).first();

test.setTimeout(120_000);

async function openCreate(page) {
    await page.goto('/app/hrms/employees');
    await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
    await page.getByRole('button', { name: 'New employee' }).click();
    await expect(page.getByText('An employment record, and optionally a sign-in account')).toBeVisible();
}

test.describe.serial('HRMS tab 4 — Employees (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('create: Full name is required', async ({ page }) => {
        await openCreate(page);
        await dialog(page).getByRole('button', { name: 'Create employee' }).click();
        // Native `required` blocks the submit: the field is invalid and the dialog stays open.
        expect(await dialog(page).getByPlaceholder('Full name').evaluate((el) => el.validity.valueMissing)).toBe(true);
        await expect(page.getByText('An employment record, and optionally a sign-in account')).toBeVisible();
    });

    test('create: an invalid personal email is blocked by the form', async ({ page }) => {
        await openCreate(page);
        await dialog(page).getByPlaceholder('Full name').fill('QA-WF-Invalid');
        await dialog(page).getByPlaceholder('Personal email').fill('not-an-email');
        await dialog(page).getByRole('button', { name: 'Create employee' }).click();
        expect(await dialog(page).getByPlaceholder('Personal email').evaluate((el) => el.validity.typeMismatch)).toBe(true);
        await expect(page.getByText('An employment record, and optionally a sign-in account')).toBeVisible();
    });

    test('create: a future date of birth is refused by the server and nothing is saved', async ({ page }) => {
        await openCreate(page);
        await dialog(page).getByPlaceholder('Full name').fill('QA-WF-Future');
        await dialog(page).getByPlaceholder('Date of birth').fill('2999-01-01');
        await dialog(page).getByPlaceholder('Full name').click();
        await dialog(page).getByRole('button', { name: 'Create employee' }).click();
        await expect(page.getByText(/before today|must be a date before/i).first()).toBeVisible();
        await page.goto('/app/hrms/employees');
        await expect(page.getByText('QA-WF-Future')).toHaveCount(0);
    });

    test('create: a valid employee is saved, listed with a code, and survives a reload', async ({ page }) => {
        await openCreate(page);
        await dialog(page).getByPlaceholder('Full name').fill(NAME);
        await dialog(page).getByPlaceholder('Designation').fill('QA Tester');
        await dialog(page).getByRole('button', { name: 'Create employee' }).click();
        await expect(page.getByRole('link', { name: NAME })).toBeVisible({ timeout: 30_000 });
        await page.reload();
        const row = page.getByRole('row').filter({ hasText: NAME });
        await expect(row).toBeVisible();
        await expect(row).toContainText(/EMP-\d+/);
        await expect(row).toContainText('QA Tester');
        await expect(row).toContainText('Active');
    });

    test('read: search narrows the list, an unknown term shows no matches', async ({ page }) => {
        await page.goto('/app/hrms/employees');
        await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
        await page.getByPlaceholder('Name or code').fill(NAME);
        await expect(page.getByRole('link', { name: NAME })).toBeVisible();
        await expect(page.getByRole('link', { name: 'QA HR Executive' })).toHaveCount(0, { timeout: 20_000 });
        await page.getByPlaceholder('Name or code').fill('zzz-no-such-employee');
        await expect(page.getByRole('link', { name: NAME })).toHaveCount(0, { timeout: 20_000 });
    });

    test('read: the Status filter separates Active from Probation', async ({ page }) => {
        await page.goto('/app/hrms/employees');
        await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
        await page.getByPlaceholder('Name or code').fill(NAME);
        await page.locator('select').filter({ has: page.locator('option', { hasText: 'Probation' }) }).first().selectOption({ label: 'Probation' });
        await expect(page.getByRole('link', { name: NAME })).toHaveCount(0);
    });

    test('update: edited designation persists after a reload', async ({ page }) => {
        await page.goto('/app/hrms/employees');
        await page.getByRole('link', { name: NAME }).click();
        await expect(page).toHaveURL(/\/hrms\/employees\/\d+/);
        await page.getByRole('button', { name: 'Edit details' }).click();
        const d = dialog(page);
        await d.getByPlaceholder('Designation').fill(EDITED);
        await d.getByRole('button', { name: /save|update/i }).last().click();
        await page.reload();
        await expect(page.getByText(EDITED).first()).toBeVisible();
    });

    test('detail: Documents, Tasks and Audit tabs render without a failing API call', async ({ page }) => {
        const errs = [];
        await page.goto('/app/hrms/employees');
        await page.getByRole('link', { name: NAME }).click();
        page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) errs.push(`${r.status()} ${r.url()}`); });
        for (const tab of ['Documents', 'Tasks', 'Audit', 'Overview']) {
            await page.getByRole('tab', { name: tab }).or(page.getByRole('button', { name: tab })).first().click();
            await page.waitForTimeout(600);
        }
        expect(errs).toEqual([]);
    });
});

// The SPA has no delete button (exit is handled by Offboarding), so deletion is verified through the
// API as the admin: the record leaves the directory, and only QA-WF- records created here are touched.
test('delete: soft-deleted test employees disappear from the directory (cleanup of QA-WF- records)', async ({ page }) => {
    await useSession(page);
    await page.goto('/app/hrms');
    const list = await apiCall(page, 'GET', '/hrms/employees?q=QA-WF-&per_page=50');
    const mine = list.json.employees.filter((e) => (e.display_name ?? e.name).startsWith('QA-WF-'));
    expect(mine.length).toBeGreaterThan(0);
    for (const e of mine) {
        const res = await apiCall(page, 'DELETE', `/hrms/employees/${e.id}`);
        expect(res.status).toBe(200);
    }
    await page.goto('/app/hrms/employees');
    await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('link', { name: /QA-WF-/ })).toHaveCount(0);
});

test.describe('HRMS tab 4 — Employees (roles)', () => {
    test('Employee persona is refused the directory with the missing permission', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/employees');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.employees\.view/)).toBeVisible();
    });

    test('HR Executive can open the directory and sees employees', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await page.goto('/app/hrms/employees');
        await expect(page.getByRole('heading', { name: 'Employees' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'QA Employee' })).toBeVisible();
    });

    test('Employee persona cannot call the employees API', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        const status = await page.evaluate(async () => (await fetch('/api/hrms/employees', { headers: { Accept: 'application/json' } })).status);
        expect(status).toBe(403);
    });

    test('Employee persona cannot delete an employee', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        const res = await apiCall(page, 'DELETE', '/hrms/employees/1');
        expect(res.status).toBe(403);
    });
});
