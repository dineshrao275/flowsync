import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE } from './helpers-pay';

// Integration evidence for the expense flow (hrms-23b): the people involved can see what happened.
test.describe('Expense flow leaves an audit trail and notifies the claimant', () => {
    test('the audit trail carries the override approval and the decision', async ({ page }) => {
        await as(page, ADMIN);
        for (const action of ['approval.override_approved', 'expense.approved']) {
            await page.goto('/app/hrms/audit');
            await expect(page.getByRole('heading', { name: 'Audit log', level: 2 })).toBeVisible({ timeout: 20_000 });
            await page.locator('main form').getByLabel('Action', { exact: true }).fill(action);
            await page.getByRole('button', { name: 'Apply' }).click();
            await expect(page.getByText(/^[1-9]\d* rows?$/)).toBeVisible({ timeout: 15_000 });
        }
    });

    test('the claimant is told their claim was decided', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/notifications');
        await expect(page.getByText(/Your claim EXP-\d{4}-\d+ was (approved|rejected)/).first()).toBeVisible({ timeout: 20_000 });
    });
});
