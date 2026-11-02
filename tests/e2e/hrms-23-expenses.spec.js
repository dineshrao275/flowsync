import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE, HR_EXEC } from './helpers-pay';

const PURPOSE = 'QA-PY-Taxi to client';
const claimRow = (page) => page.getByRole('row').filter({ hasText: PURPOSE });
const confirmDelete = (page) => page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last();

async function openQueue(page) {
    await as(page, ADMIN);
    await page.goto('/app/hrms/expenses');
    await expect(page.getByText(/\d+ claims?/).first()).toBeVisible();
    await page.waitForLoadState('networkidle');
}

test.describe.serial('HRMS tab 23 — Expenses queue (admin)', () => {
    test.beforeEach(async ({ page }) => { await openQueue(page); });

    test('the employee’s submitted claim is in the queue and the status filter narrows it', async ({ page }) => {
        await expect(claimRow(page)).toBeVisible();
        await expect(claimRow(page)).toContainText('QA Employee');
        await page.getByLabel('Status').selectOption('paid');
        await expect(claimRow(page)).toHaveCount(0, { timeout: 20000 });
        await page.getByLabel('Status').selectOption('');
        await expect(claimRow(page)).toBeVisible({ timeout: 20000 });
    });

    test('a decision cannot approve more than was filed', async ({ page }) => {
        test.skip(await claimRow(page).getByRole('button', { name: 'Decide' }).count() === 0, 'claim already decided');
        await claimRow(page).getByRole('button', { name: 'Decide' }).click();
        const d = page.getByRole('dialog', { name: /EXP-/ });
        await d.getByLabel(/Approved amount/i).fill('900');
        await d.getByRole('button', { name: /record decision/i }).click();
        await expect(d).toBeVisible();
        await expect(claimRow(page)).not.toContainText(/approved|paid/);
    });

    test('approving while the approval chain is unresolved is refused, and the refusal is shown', async ({ page }) => {
        test.skip(await claimRow(page).getByRole('button', { name: 'Decide' }).count() === 0, 'claim already decided');
        await claimRow(page).getByRole('button', { name: 'Decide' }).click();
        const d = page.getByRole('dialog', { name: /EXP-/ });
        await d.getByLabel(/Approved amount/i).fill('450');
        await d.getByLabel(/Reason/i).fill('QA-PY over policy rate');
        await d.getByRole('button', { name: /record decision/i }).click();
        // Business rule: only a resolved chain approves. The modal must say so rather than do nothing.
        await expect(d.getByText('Resolve the approval chain before approving.')).toBeVisible({ timeout: 15000 });
        await expect(claimRow(page)).toContainText('submitted');
    });

    test('a rejection needs a reason, then records and persists', async ({ page }) => {
        test.skip(await claimRow(page).getByRole('button', { name: 'Decide' }).count() === 0, 'claim already decided');
        await claimRow(page).getByRole('button', { name: 'Decide' }).click();
        const d = page.getByRole('dialog', { name: /EXP-/ });
        await d.getByLabel('Verdict').selectOption('reject');
        await d.getByRole('button', { name: /record decision/i }).click();
        await expect(d).toBeVisible(); // no reason: refused, modal stays
        await d.getByLabel(/^Reason/i).fill('QA-PY not a business trip');
        await d.getByRole('button', { name: /record decision/i }).click();
        await expect(page.getByText('Claim rejected.')).toBeVisible({ timeout: 15000 });
        await page.reload();
        await expect(claimRow(page)).toContainText('rejected');
    });
});

const CAT = 'QA-PY-Category';
const catItem = (page) => page.getByRole('listitem').filter({ hasText: CAT });

test.describe.serial('HRMS tab 23 — Expense categories (admin)', () => {
    test.beforeEach(async ({ page }) => { await openQueue(page); });

    test('a category needs a name', async ({ page }) => {
        await page.waitForLoadState('networkidle');
        const before = await page.getByRole('listitem').count();
        await page.getByRole('button', { name: 'Add category' }).click();
        await page.waitForTimeout(1000);
        expect(await page.getByRole('listitem').count()).toBe(before);
    });

    test('adds a category with limits, and a duplicate name is refused', async ({ page }) => {
        while (await catItem(page).count()) {
            await catItem(page).first().getByRole('button', { name: 'Delete' }).click();
            await confirmDelete(page).click();
            await page.waitForTimeout(700);
        }
        const add = async () => {
            await page.getByLabel('Name', { exact: true }).fill(CAT);
            await page.getByLabel(/Receipt over/).fill('100');
            await page.getByLabel(/Max per line/).fill('1000');
            await page.getByLabel(/Monthly limit/).fill('5000');
            await page.getByRole('button', { name: 'Add category' }).click();
        };
        await add();
        await expect(catItem(page)).toBeVisible();
        await expect(catItem(page)).toContainText(/receipt over 100/i);
        await add();
        await expect(catItem(page)).toHaveCount(1);
        await page.reload();
        await expect(catItem(page)).toHaveCount(1);
    });

    test('a starter category cannot be deleted, an own one can', async ({ page }) => {
        const travel = page.getByRole('listitem').filter({ hasText: /^Travel/ });
        await travel.getByRole('button', { name: 'Delete' }).click();
        await confirmDelete(page).click();
        await page.waitForTimeout(800);
        await page.reload();
        await expect(page.getByRole('listitem').filter({ hasText: /^Travel/ })).toBeVisible();

        await catItem(page).first().getByRole('button', { name: 'Delete' }).click();
        await confirmDelete(page).click();
        await page.reload();
        await expect(catItem(page)).toHaveCount(0);
    });
});

test.describe('HRMS tab 23 — Expenses: roles', () => {
    for (const [label, persona] of [['Employee', EMPLOYEE], ['HR Executive', HR_EXEC]]) {
        test(`${label} is refused the queue`, async ({ page }) => {
            await as(page, persona);
            await page.goto('/app/hrms/expenses');
            await expect(page.getByText('Access denied')).toBeVisible();
            await expect(page.getByText(/hrms\.expenses\.view/)).toBeVisible();
        });
    }
});
