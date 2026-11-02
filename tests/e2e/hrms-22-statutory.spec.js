import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE, HR_EXEC, watchApi } from './helpers-pay';

const dialog = (page) => page.getByRole('dialog', { name: /exemption|claim|declar/i });

test.describe('HRMS tab 22 — Statutory: Employee self-service', () => {
    test('the page loads for an Employee without a forbidden background call', async ({ page }) => {
        await as(page, EMPLOYEE);
        const errs = watchApi(page);
        await page.goto('/app/hrms/statutory');
        await expect(page.getByText('Restricted data')).toBeVisible();
        await page.waitForLoadState('networkidle');
        expect(errs).toEqual([]);
    });

    test('an Employee files their own claim as a draft and stays on the page', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/statutory');
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: 'File a claim' }).click();
        const d = dialog(page);
        await expect(d.getByText('QA Employee')).toBeVisible(); // own record preselected
        await d.getByLabel('Section').fill('80c');
        await d.getByLabel(/Declared amount/i).fill('15000');
        await d.getByRole('button', { name: /file as draft/i }).click();
        await expect(page.getByText(/Declaration filed|claim filed/i)).toBeVisible();
        await expect(page).toHaveURL(/\/hrms\/statutory/);
        await expect(page.getByText('Access denied')).toHaveCount(0);
    });
});

test.describe('HRMS tab 22 — Statutory: roles', () => {
    test('HR Executive (no statutory permission on the page) is not shown rulebooks', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/statutory');
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('button', { name: 'New rulebook' })).toHaveCount(0);
    });

    test('admin sees the rulebook, claims and projection sections', async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/statutory');
        await expect(page.getByRole('button', { name: 'New rulebook' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'File a claim' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Project year' })).toBeVisible();
    });
});

const RB = 'qa_py_rb'; // the table lists the code, not the name
const rdialog = (page) => page.getByRole('dialog', { name: /rulebook/i });
const rbRow = (page) => page.getByRole('row').filter({ hasText: RB });

async function pickPerson(page, label, name) {
    const box = page.getByLabel(label, { exact: true }).first();
    await box.click();
    await box.fill(name);
    await page.getByRole('option').filter({ hasText: name }).first().click();
}

test.describe.serial('HRMS tab 22 — Statutory: rulebooks (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/statutory');
        await expect(page.getByRole('button', { name: 'New rulebook' })).toBeVisible();
        await page.waitForLoadState('networkidle');
    });

    test('rules JSON must be valid JSON', async ({ page }) => {
        await page.getByRole('button', { name: 'New rulebook' }).click();
        await rdialog(page).getByLabel('Code').fill('qa_py_bad');
        await rdialog(page).getByLabel(/Country/).fill('IN');
        await rdialog(page).getByLabel('Name').fill('QA-PY-BadJson');
        await rdialog(page).getByLabel(/Rules JSON/).fill('{not json');
        await rdialog(page).getByRole('button', { name: 'Create rulebook' }).click();
        await expect(rdialog(page).getByText('That is not valid JSON.')).toBeVisible();
    });

    test('create, edit and delete a rulebook, persisting across reloads', async ({ page }) => {
        while (await rbRow(page).count()) {
            await rbRow(page).first().getByRole('button', { name: 'Delete' }).click();
            await page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last().click();
            await page.waitForTimeout(700);
        }
        await page.getByRole('button', { name: 'New rulebook' }).click();
        await rdialog(page).getByLabel('Code').fill('qa_py_rb');
        await rdialog(page).getByLabel(/Country/).fill('IN');
        await rdialog(page).getByLabel('Name').fill(RB);
        await rdialog(page).getByLabel(/Rules JSON/).fill('{"pf": {"enabled": true}}');
        await rdialog(page).getByRole('button', { name: 'Create rulebook' }).click();
        await expect(rbRow(page)).toBeVisible();

        await page.reload();
        await expect(rbRow(page)).toBeVisible();
        await rbRow(page).getByRole('button', { name: 'Edit' }).click();
        await rdialog(page).getByLabel(/Region/).fill('KA');
        await rdialog(page).getByRole('button', { name: 'Save rulebook' }).click();
        await page.reload();
        await expect(rbRow(page)).toContainText('KA');

        await rbRow(page).getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last().click();
        await page.reload();
        await expect(rbRow(page)).toHaveCount(0);
    });
});

test.describe.serial('HRMS tab 22 — Statutory: identifiers and claims (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/statutory');
        await expect(page.getByRole('button', { name: 'New rulebook' })).toBeVisible();
        await page.waitForLoadState('networkidle');
    });

    test('identifiers: invalid PAN refused, valid saved masked, a one-field save keeps the rest, reveal is explicit', async ({ page }) => {
        await pickPerson(page, 'Employee', 'QA Employee');
        await expect(page.getByLabel('PAN', { exact: true })).toBeVisible();

        await page.getByLabel('PAN', { exact: true }).fill('abc');
        await page.getByRole('button', { name: 'Save identifiers' }).click();
        await expect(page.getByText(/pan.*(format|invalid)/i).first()).toBeVisible();
        await expect(page.getByText('Profile saved.')).toHaveCount(0);

        await page.getByLabel('PAN', { exact: true }).fill('abcde1234f');
        await page.getByLabel(/Aadhaar/).fill('123456789012');
        await page.getByRole('button', { name: 'Save identifiers' }).click();
        await expect(page.getByText('Profile saved.')).toBeVisible();
        await expect(page.locator('main')).toContainText('XXXXX1234F');
        await expect(page.locator('main')).not.toContainText('ABCDE1234F');
        await expect(page.locator('main')).not.toContainText('123456789012');

        // Editing a single field must not erase the others.
        await page.getByLabel('Bank name').fill('QA-PY Bank');
        await page.getByRole('button', { name: 'Save identifiers' }).click();
        await expect(page.locator('main')).toContainText('XXXXX1234F');
        await page.reload();
        await pickPerson(page, 'Employee', 'QA Employee');
        await expect(page.locator('main')).toContainText('XXXXX1234F');

        await page.getByRole('button', { name: /Reveal cleartext/ }).click();
        await expect(page.getByText(/Cleartext —/).first()).toBeVisible();
        await expect(page.locator('main')).toContainText('ABCDE1234F');
    });

    test('an admin files a claim for QA Employee', async ({ page }) => {
        await page.getByRole('button', { name: 'File a claim' }).click();
        const d = page.getByRole('dialog', { name: /exemption claim/i });
        await pickPersonIn(d, page, 'QA Employee');
        await d.getByLabel('Section').fill('80c');
        await d.getByLabel(/Declared amount/i).fill('20000');
        await d.getByRole('button', { name: /file as draft/i }).click();
        await expect(page.getByText('Claim filed as a draft.')).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: /80c/i }).first()).toContainText('QA Employee');
    });
});

test.describe.serial('HRMS tab 22 — Statutory: claim decisions (admin)', () => {
    test('a draft claim is submitted, then verified; another is submitted, then rejected', async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/statutory');
        await page.waitForLoadState('networkidle');
        const rows = page.getByRole('row').filter({ hasText: /QA Employee/ });
        await expect(rows.first()).toBeVisible();

        test.skip((await rows.count()) < 2, 'needs two claims from the earlier tests');
        const drafts = rows.filter({ hasText: 'draft' });
        test.skip((await drafts.count()) < 2, 'claims already decided in an earlier run');

        await drafts.nth(0).getByRole('button', { name: 'Submit' }).click();
        await expect(page.getByText('Claim submitted.')).toBeVisible();
        await expect(rows.filter({ hasText: 'submitted' }).first()).toBeVisible();
        await rows.filter({ hasText: 'submitted' }).first().getByRole('button', { name: 'Verify' }).click();
        await expect(page.getByText('Claim verified.')).toBeVisible();

        await drafts.nth(0).getByRole('button', { name: 'Submit' }).click();
        await expect(rows.filter({ hasText: 'submitted' }).first()).toBeVisible();
        await rows.filter({ hasText: 'submitted' }).first().getByRole('button', { name: 'Reject' }).click();
        await expect(page.getByText('Claim rejected.')).toBeVisible();
        await page.reload();
        await expect(rows.first()).toBeVisible();
        const all = await rows.allInnerTexts();
        expect(all.join(' ')).toMatch(/verified/);
        expect(all.join(' ')).toMatch(/rejected/);
    });
});

async function pickPersonIn(scope, page, name) {
    const box = scope.getByLabel('Employee', { exact: true });
    await box.click();
    await box.fill(name);
    await page.getByRole('option').filter({ hasText: name }).first().click();
}

test.describe('HRMS tab 22 — Statutory: TDS projection and recompute (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/statutory');
        await expect(page.getByRole('button', { name: 'Project year' })).toBeVisible();
        await page.waitForLoadState('networkidle');
    });

    test('projecting a year says why when the jurisdiction has no TDS rulebook', async ({ page }) => {
        const section = page.getByText('TDS projection').first().locator('xpath=ancestor::*[.//button[normalize-space()="Project year"]][1]');
        const box = section.getByLabel('Employee', { exact: true });
        await box.click();
        await box.fill('QA Employee');
        await page.getByRole('option').filter({ hasText: 'QA Employee' }).first().click();
        await section.getByLabel('Fiscal year').fill('2026');
        await section.getByRole('button', { name: 'Project year' }).click();
        await expect(page.getByText(/no TDS rulebook for this employee/)).toBeVisible();
    });

    test('recompute of a locked run is refused with the explanation', async ({ page }) => {
        await page.getByLabel('Run id').fill('1');
        await page.getByRole('button', { name: 'Recompute', exact: true }).click();
        await expect(page.getByText('That run refuses a recompute — only review runs accept one.')).toBeVisible();
    });

    test('recompute of a missing run id does not crash the page', async ({ page }) => {
        await page.getByLabel('Run id').fill('999999');
        await page.getByRole('button', { name: 'Recompute', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Recompute', exact: true })).toBeVisible();
        await expect(page.getByText('Access denied')).toHaveCount(0);
    });
});
