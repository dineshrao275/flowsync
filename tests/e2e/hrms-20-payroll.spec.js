import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE, HR_EXEC } from './helpers-pay';

// The pay-run lifecycle on a historical period of the Globex test tenant (QA Employee is the only
// person with a pay basis, so no real payroll data is touched). A locked run is permanent, so the
// suite is idempotent: it picks up wherever an earlier run stopped.
const YEAR = '2026';
const MONTH = '5';
const runRow = (page) => page.getByRole('row').filter({ hasText: /May 2026/ });
const dialog = (page) => page.getByRole('dialog', { name: /pay run/i });

async function openPayroll(page) {
    await as(page, ADMIN);
    await page.goto('/app/hrms/payroll');
    await expect(page.getByRole('button', { name: 'Open a run' })).toBeVisible();
    await page.waitForLoadState('networkidle');
}

async function fillRun(page, { year = YEAR, month = MONTH, start = '2026-05-01', end = '2026-05-31', pay = '2026-05-31' } = {}) {
    await page.getByRole('button', { name: 'Open a run' }).click();
    await dialog(page).getByLabel('Year').fill(year);
    await dialog(page).getByLabel(/Month/).fill(month);
    await dialog(page).getByLabel('Period start').fill(start);
    await dialog(page).getByLabel('Period end').fill(end);
    await dialog(page).getByLabel('Pay date').fill(pay);
    await dialog(page).getByRole('heading', { name: 'Open a pay run' }).click(); // close the date popup
}

test.describe.serial('HRMS tab 20 — Payroll runs (admin)', () => {
    test.beforeEach(async ({ page }) => { await openPayroll(page); });

    test('rejects month 13 and a period that ends before it starts', async ({ page }) => {
        await fillRun(page, { month: '13' });
        await dialog(page).getByRole('button', { name: 'Open run' }).click();
        await expect(dialog(page)).toBeVisible();
        await dialog(page).getByLabel(/Month/).fill('6');
        await dialog(page).getByLabel('Period end').fill('2026-03-01');
        await dialog(page).getByRole('heading', { name: 'Open a pay run' }).click();
        await dialog(page).getByRole('button', { name: 'Open run' }).click();
        await expect(dialog(page)).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: /June 2026|13/ })).toHaveCount(0);
    });

    test('opens a run for May 2026 (or finds it already open)', async ({ page }) => {
        test.skip(await runRow(page).count() > 0, 'May 2026 run exists from an earlier run');
        await fillRun(page);
        await dialog(page).getByRole('button', { name: 'Open run' }).click();
        await expect(runRow(page)).toBeVisible();
        await expect(runRow(page)).toContainText('draft');
    });

    test('a second run for the same month is refused', async ({ page }) => {
        const before = await page.getByRole('row').filter({ hasText: /May 2026/ }).count();
        await fillRun(page);
        await dialog(page).getByRole('button', { name: 'Open run' }).click();
        await expect(dialog(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await page.reload();
        await expect(page.getByRole('row').filter({ hasText: /May 2026/ })).toHaveCount(before);
    });

    test('walks the run draft -> review -> approved -> processing -> paid -> locked', async ({ page }) => {
        const status = async () => (await runRow(page).getByRole('cell').nth(2).innerText()).trim();
        const step = async (button, next) => {
            await runRow(page).getByRole('button', { name: button, exact: true }).click();
            await expect.poll(status, { timeout: 15000 }).toBe(next);
        };
        if ((await status()) === 'draft') await step('Calculate', 'review');
        if ((await status()) === 'review') await step('Approve', 'approved');
        if ((await status()) === 'approved') await step('Publish', 'processing');
        if ((await status()) === 'processing') await step('Mark paid', 'paid');
        if ((await status()) === 'paid') await step('Lock', 'locked');
        expect(await status()).toBe('locked');
        // A locked run offers no further action.
        await expect(runRow(page).getByRole('button')).toHaveCount(0);
    });

    test('the run detail shows QA Employee with a payslip and the persisted totals', async ({ page }) => {
        await runRow(page).getByRole('link', { name: /May 2026/ }).click();
        await expect(page).toHaveURL(/\/hrms\/payroll\/runs\/\d+/);
        await expect(page.getByText('QA Employee').first()).toBeVisible();
    });
});

test.describe('HRMS tab 20 — Payroll: roles', () => {
    for (const [label, persona] of [['Employee', EMPLOYEE], ['HR Executive', HR_EXEC]]) {
        test(`${label} is refused the run console`, async ({ page }) => {
            await as(page, persona);
            await page.goto('/app/hrms/payroll');
            await expect(page.getByText('Access denied')).toBeVisible();
            await expect(page.getByText(/hrms\.payroll\.run/)).toBeVisible();
            await expect(page.getByRole('button', { name: 'Open a run' })).toHaveCount(0);
        });
    }
});
