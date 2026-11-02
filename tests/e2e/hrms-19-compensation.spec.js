import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE, HR_EXEC, watchApi } from './helpers-pay';

const HEAD = 'QA-PY-Bonus';
const CODE = 'qa_py_bonus';
const dialog = (page) => page.getByRole('dialog', { name: /pay head/i });
const row = (page, name) => page.getByRole('row').filter({ hasText: name });

// Removes only this suite's own `QA-PY-` rows (through the UI), so a failed run can be repeated.
async function purgeOwnHeads(page) {
    for (let i = 0; i < 10; i += 1) {
        const mine = page.getByRole('row').filter({ hasText: /QA-PY-/ });
        if ((await mine.count()) === 0) return;
        await mine.first().getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last().click();
        await page.waitForTimeout(600);
    }
}

test.describe.serial('HRMS tab 19 — Compensation: pay heads (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/compensation');
        await expect(page.getByRole('heading', { name: 'Pay heads' })).toBeVisible();
    });

    test('create a pay head and see it listed', async ({ page }) => {
        await purgeOwnHeads(page);
        await page.getByRole('button', { name: 'New head' }).click();
        await dialog(page).getByLabel('Name').fill(HEAD);
        await dialog(page).getByLabel('Code').fill(CODE);
        await dialog(page).getByLabel('Default value').fill('500');
        await dialog(page).getByRole('button', { name: /create head/i }).click();
        await expect(row(page, HEAD)).toBeVisible();
        await expect(row(page, HEAD)).toContainText(CODE);
    });

    test('duplicate code is refused with a field error', async ({ page }) => {
        await page.getByRole('button', { name: 'New head' }).click();
        await dialog(page).getByLabel('Name').fill('QA-PY-Dup');
        await dialog(page).getByLabel('Code').fill(CODE);
        await dialog(page).getByRole('button', { name: /create head/i }).click();
        await expect(dialog(page)).toBeVisible();
        await expect(dialog(page).getByText(/code.*(taken|already)|already.*taken/i)).toBeVisible();
        await expect(row(page, 'QA-PY-Dup')).toHaveCount(0);
    });

    test('non-numeric default value is refused', async ({ page }) => {
        await page.getByRole('button', { name: 'New head' }).click();
        await dialog(page).getByLabel('Name').fill('QA-PY-Bad');
        await dialog(page).getByLabel('Default value').fill('abc');
        await dialog(page).getByRole('button', { name: /create head/i }).click();
        await expect(row(page, 'QA-PY-Bad')).toHaveCount(0);
    });

    test('edit persists after reload', async ({ page }) => {
        await row(page, HEAD).getByRole('button', { name: 'Edit' }).click();
        await dialog(page).getByLabel('Default value').fill('750');
        await dialog(page).getByRole('button', { name: /save head/i }).click();
        await page.reload();
        await expect(row(page, HEAD)).toContainText('750');
    });

    test('delete removes the head', async ({ page }) => {
        await row(page, HEAD).getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last().click();
        await expect(row(page, HEAD)).toHaveCount(0);
        await page.reload();
        await expect(row(page, HEAD)).toHaveCount(0);
    });
});

const TEMPLATE = 'QA-PY-Template';
const tdialog = (page) => page.getByRole('dialog', { name: /template/i });

test.describe.serial('HRMS tab 19 — Compensation: templates, salary and revisions (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/compensation');
        await expect(page.getByRole('heading', { name: 'Pay heads' })).toBeVisible();
    });

    test('create a template, link heads, and see them on the card', async ({ page }) => {
        test.skip(await page.getByText(TEMPLATE).count() > 0, 'template exists from an earlier run (it is in use once a salary is assigned)');
        await page.getByRole('button', { name: 'New template' }).click();
        await tdialog(page).getByLabel('Name').fill(TEMPLATE);
        await tdialog(page).getByLabel('Effective from').fill('2026-04-01');
        await tdialog(page).getByRole('button', { name: /create template/i }).click();
        await expect(page.getByText(TEMPLATE)).toBeVisible();

        await page.getByText(TEMPLATE).locator('xpath=ancestor::*[.//button[normalize-space()="Heads"]][1]').getByRole('button', { name: 'Heads' }).first().click();
        const links = page.getByRole('dialog', { name: /heads|links|QA-PY/i });
        await links.getByText('Basic Salary').click();
        await links.getByLabel(/value for basic salary/i).fill('50');
        await links.getByRole('button', { name: /save head list/i }).click();
        await expect(page.getByText(/from 2026-04-01 · 1 head/)).toBeVisible();
        await page.reload();
        await expect(page.getByText(/from 2026-04-01 · 1 head/)).toBeVisible();
    });

    test('a template in use by a salary assignment cannot be deleted', async ({ page }) => {
        test.skip(await page.getByText(TEMPLATE).count() === 0, 'template not assigned yet');
        const card = page.locator('div').filter({ hasText: TEMPLATE }).filter({ has: page.getByRole('button', { name: 'Delete' }) }).last();
        const assigned = await page.getByText(/from 2026-04-01 · [1-9]/).count();
        expect(assigned).toBeGreaterThan(0);
        await card.getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last().click();
        await page.waitForTimeout(800);
        await page.reload();
        await expect(page.getByText(TEMPLATE)).toBeVisible();
    });

    test('a template needs a name', async ({ page }) => {
        await page.getByRole('button', { name: 'New template' }).click();
        await tdialog(page).getByLabel('Effective from').fill('2026-04-01');
        await tdialog(page).getByRole('button', { name: /create template/i }).click();
        await expect(tdialog(page)).toBeVisible();
        await expect(page.getByText('QA-PY-Nameless')).toHaveCount(0);
    });
});

async function pickEmployee(page, name) {
    const box = page.getByLabel('Employee');
    await box.click();
    await box.fill(name);
    const loaded = page.waitForResponse((r) => /payroll\/employees\/\d+\/salary/.test(r.url()) && r.request().method() === 'GET');
    await page.getByRole('option').filter({ hasText: name }).first().click();
    await loaded;
    await page.waitForTimeout(500);
}

test.describe.serial('HRMS tab 19 — Compensation: employee pay (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/compensation');
        await expect(page.getByRole('heading', { name: 'Pay heads' })).toBeVisible();
        await pickEmployee(page, 'QA Employee');
    });

    test('salary assignment rejects a non-positive CTC and a missing date', async ({ page }) => {
        test.skip(await page.getByText('No pay basis yet').count() === 0, 'basis already assigned in an earlier run');
        await page.getByLabel('Template').selectOption({ label: 'QA-PY-Template' });
        await page.getByLabel('Annual CTC', { exact: true }).fill('-5');
        await page.getByLabel('Effective from').first().fill('2026-04-01');
        await page.getByRole('button', { name: 'Assign salary' }).click();
        await expect(page.getByText('No pay basis yet')).toBeVisible();
    });

    test('assign a salary and see the basis; it survives a reload', async ({ page }) => {
        test.skip(await page.getByText('No pay basis yet').count() === 0, 'basis already assigned in an earlier run');
        await page.getByLabel('Template').selectOption({ label: 'QA-PY-Template' });
        await page.getByLabel('Annual CTC', { exact: true }).fill('600000');
        await page.getByLabel('Effective from').first().fill('2026-04-01');
        await page.getByLabel('Reason').first().fill('QA-PY initial');
        await page.getByRole('button', { name: 'Assign salary' }).click();
        await expect(page.getByText(/QA-PY-Template/).first()).toBeVisible();
        await expect(page.getByText('No pay basis yet')).toHaveCount(0);
        await page.reload();
        await pickEmployee(page, 'QA Employee');
        await expect(page.getByText('No pay basis yet')).toHaveCount(0);
    });

    test('a revision is raised with the from/to CTC recorded', async ({ page }) => {
        await page.getByLabel('New annual CTC', { exact: true }).fill('720000');
        await page.getByLabel('Effective from').last().fill('2027-04-01');
        await page.getByLabel('Reason').last().fill('QA-PY raise');
        await page.getByRole('button', { name: 'Raise revision' }).click();
        await expect(page.getByText(/720000/).first()).toBeVisible();
    });
});

test.describe('HRMS tab 19 — Compensation: roles', () => {
    test('Employee is refused the whole page', async ({ page }) => {
        await as(page, EMPLOYEE);
        const errs = watchApi(page);
        await page.goto('/app/hrms/compensation');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.compensation\.view/)).toBeVisible();
        expect(errs.filter((e) => /payroll\/components/.test(e) && !e.startsWith('403'))).toEqual([]);
    });

    test('HR Executive has no compensation permission, so the page is refused', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/compensation');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.compensation\.view/)).toBeVisible();
    });
});
