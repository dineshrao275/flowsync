import { test, expect } from '@playwright/test';
import { as, EMPLOYEE, HR_EXEC, watchApi } from './helpers-pay';

const PURPOSE = 'QA-PY-Taxi to client';

test.describe.serial('HRMS tab 24 — My expenses (Employee)', () => {
    test('the page loads with no forbidden background call and offers the file button', async ({ page }) => {
        await as(page, EMPLOYEE);
        const errs = watchApi(page);
        await page.goto('/app/hrms/expenses/mine');
        await expect(page.getByRole('button', { name: /file/i }).first()).toBeVisible();
        await page.waitForLoadState('networkidle');
        expect(errs).toEqual([]);
    });
});

const RECEIPT = '/tmp/claude-1000/-home-dineshrao-Public-flowsync/a031d850-6887-4bae-b576-18730569d466/scratchpad/files/receipt.pdf';
const dlg = (page) => page.getByRole('dialog', { name: /claim/i });
const claimRow = (page) => page.getByRole('listitem').filter({ hasText: PURPOSE });

async function fillClaim(page, { purpose = PURPOSE, amount = '500', receipt = true, category = 'Meals' } = {}) {
    await page.getByRole('button', { name: /file/i }).first().click();
    const d = dlg(page);
    await d.getByLabel('Claim date').fill('2026-05-10');
    await d.getByLabel('Purpose').fill(purpose);
    await d.getByLabel('Year', { exact: true }).fill('2026');
    await d.getByLabel(/^Month/).fill('5');
    await d.getByLabel('Line 1 category').selectOption({ label: category });
    await d.getByLabel('Amount').first().fill(amount);
    await d.getByLabel('Description').first().fill('QA-PY line');
    if (receipt) await d.locator('input[type=file]').first().setInputFiles(RECEIPT);
    await d.getByRole('heading', { name: 'File a claim' }).click(); // close any date popup
    return d;
}

test.describe.serial('HRMS tab 24 — My expenses: filing and submitting (Employee)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/expenses/mine');
        await expect(page.getByRole('button', { name: /file/i }).first()).toBeVisible();
        await page.waitForLoadState('networkidle');
    });

    test('a claim needs a purpose and a positive amount', async ({ page }) => {
        const before = await page.getByRole('listitem').count();
        const d = await fillClaim(page, { purpose: '', amount: '0', category: 'Meals', receipt: false });
        await d.getByRole('button', { name: 'File claim' }).click();
        await expect(d).toBeVisible();
        await page.waitForTimeout(1000);
        expect(await page.getByRole('listitem').count()).toBe(before);
    });

    test('a category that needs a receipt refuses a claim without one', async ({ page }) => {
        const d = await fillClaim(page, { category: 'Travel', receipt: false, purpose: 'QA-PY no receipt' });
        await d.getByRole('button', { name: 'File claim' }).click();
        await expect(d).toBeVisible();
        await page.waitForTimeout(1000);
        await expect(page.getByRole('listitem').filter({ hasText: 'QA-PY no receipt' })).toHaveCount(0);
    });

    test('files a claim as a draft, then submits it', async ({ page }) => {
        test.skip(await claimRow(page).count() > 0, 'claim exists from an earlier run');
        const d = await fillClaim(page);
        await d.getByRole('button', { name: 'File claim' }).click();
        await expect(claimRow(page)).toBeVisible();
        await expect(claimRow(page)).toContainText(/draft/i);
        await claimRow(page).getByRole('button', { name: /^Submit/ }).click();
        await expect(page.getByText(/submitted/i).first()).toBeVisible();
        await page.reload();
        await expect(claimRow(page)).not.toContainText(/draft/i);
    });
});

test.describe('HRMS tab 24 — My expenses: other roles', () => {
    test('HR Executive without an expense claim sees only their own (empty) list', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/expenses/mine');
        await page.waitForLoadState('networkidle');
        await expect(page.getByText(PURPOSE)).toHaveCount(0);
    });
});
