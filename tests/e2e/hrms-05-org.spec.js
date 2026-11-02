import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession } from './helpers-workforce';

const RUN = Date.now().toString(36).slice(-5);
const DEPT = `QA-WF-Dept ${RUN}`;
const DEPT2 = `QA-WF-Dept ${RUN} renamed`;
const DESIG = `QA-WF-Desig ${RUN}`;
const LOC = `QA-WF-Loc ${RUN}`;
const modal = (page, title) => page.getByRole('dialog').filter({ hasText: title }).first();

test.setTimeout(120_000);

async function openOrg(page) {
    await page.goto('/app/hrms/org');
    await expect(page.getByText(/\d+ department\(s\)/)).toBeVisible({ timeout: 30_000 });
}

test.describe.serial('HRMS tab 5 — Organisation (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('department: a name is required', async ({ page }) => {
        await openOrg(page);
        await page.getByRole('button', { name: 'New department' }).click();
        await modal(page, 'New department').getByRole('button', { name: 'Create department' }).click();
        expect(await modal(page, 'New department').getByPlaceholder('Name').evaluate((el) => el.validity.valueMissing)).toBe(true);
    });

    test('department: create, persists after reload, then rename', async ({ page }) => {
        await openOrg(page);
        await page.getByRole('button', { name: 'New department' }).click();
        await modal(page, 'New department').getByPlaceholder('Name').fill(DEPT);
        await modal(page, 'New department').getByPlaceholder(/short label/).fill(`Q${RUN}`.toUpperCase());
        await modal(page, 'New department').getByRole('button', { name: 'Create department' }).click();
        await expect(page.getByText(DEPT, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
        await page.reload();
        await expect(page.getByText(DEPT, { exact: true }).first()).toBeVisible({ timeout: 30_000 });

        await page.getByText(DEPT, { exact: true }).first().click();
        // The department's own Edit sits beside Deactivate; other Edit buttons belong to designations/locations.
        await page.getByRole('button', { name: 'Deactivate' }).locator('..').getByRole('button', { name: 'Edit' }).click();
        await modal(page, 'Edit department').getByPlaceholder('Name').fill(DEPT2);
        await modal(page, 'Edit department').getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText(DEPT2, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
        await page.reload();
        await expect(page.getByText(DEPT2, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
    });

    // Valid business rule: names are not unique (OrgNaming::uniqueSlug gives each its own slug), so a
    // second department with the same name is accepted and both are listed.
    test('department: a duplicate name is accepted as a separate department', async ({ page }) => {
        await openOrg(page);
        await page.getByRole('button', { name: 'New department' }).click();
        await modal(page, 'New department').getByPlaceholder('Name').fill(DEPT2);
        await modal(page, 'New department').getByRole('button', { name: 'Create department' }).click();
        await expect(page.getByText(DEPT2, { exact: true })).toHaveCount(2, { timeout: 30_000 });
    });

    test('designation: create and edit', async ({ page }) => {
        await openOrg(page);
        await page.getByRole('button', { name: 'New designation' }).click();
        await modal(page, 'New designation').getByPlaceholder('Name').fill(DESIG);
        await modal(page, 'New designation').getByRole('button', { name: /Create designation/ }).click();
        await expect(page.getByText(DESIG, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
        await page.reload();
        const row = page.locator('div,li,tr').filter({ hasText: DESIG }).filter({ has: page.getByRole('button', { name: 'Edit' }) }).last();
        await row.getByRole('button', { name: 'Edit' }).click();
        await modal(page, 'Edit designation').getByPlaceholder('Optional — e.g. ENG').fill(`D${RUN}`.toUpperCase());
        await modal(page, 'Edit designation').getByRole('button', { name: 'Save changes' }).click();
        await expect(modal(page, 'Edit designation')).toHaveCount(0, { timeout: 30_000 });
    });

    test('location: create and persist', async ({ page }) => {
        await openOrg(page);
        await page.getByRole('button', { name: 'New location' }).click();
        await modal(page, 'New location').getByPlaceholder('Name').fill(LOC);
        await modal(page, 'New location').getByRole('button', { name: /Create location/ }).click();
        await expect(page.getByText(LOC, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
        await page.reload();
        await expect(page.getByText(LOC, { exact: true }).first()).toBeVisible({ timeout: 30_000 });
    });

    // Valid business rule: a department is deactivated (struck through, "inactive"), never removed,
    // so history that points at it stays intact. This also cleans up this run's departments.
    test('department: deactivate keeps it in the tree as inactive (cleanup of departments created by this run)', async ({ page }) => {
        await openOrg(page);
        const rows = page.getByText(DEPT2, { exact: true });
        const total = await rows.count();
        expect(total).toBe(2); // the renamed one and its same-named twin from the duplicate test
        for (let i = 0; i < total; i += 1) {
            await rows.nth(i).click();
            const deactivate = page.getByRole('button', { name: 'Deactivate' });
            if (await deactivate.count()) {
                await deactivate.click();
                // The confirm dialog adds a second "Deactivate" button; the last one is the dialog's.
                await expect(page.getByRole('button', { name: 'Deactivate' })).not.toHaveCount(0);
                await page.getByRole('button', { name: 'Deactivate' }).last().click();
            }
            await expect(page.getByRole('button', { name: 'Deactivate' })).toHaveCount(0, { timeout: 30_000 });
        }
    });
});

test.describe('HRMS tab 5 — Organisation (roles)', () => {
    test('Employee persona is refused with the missing permission', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/org');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.org\.view/)).toBeVisible();
    });

    test('HR Executive can view the org page', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openOrg(page);
        await expect(page.getByText('Engineering', { exact: true }).first()).toBeVisible();
    });
});
