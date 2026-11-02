import { test, expect } from '@playwright/test';
import { as, EMPLOYEE, HR_EXEC } from './helpers-pay';
import { PERSONAS } from './helpers';

// A manager holds the assigned scope (view_assigned), not the exact `hrms.expenses.view`. The queue gate
// accepts any viewing scope, so the page a chain approver is sent to from the Inbox is reachable.
test.describe('Expenses queue access by scope', () => {
    test('a Manager (assigned scope) reaches the queue and sees it in the sidebar', async ({ page }) => {
        await as(page, PERSONAS.manager);
        await page.goto('/app/hrms/expenses');
        await expect(page).toHaveURL(/\/app\/hrms\/expenses$/);
        await expect(page.getByText(/\d+ claims?/).first()).toBeVisible({ timeout: 20000 });
        await expect(page.getByRole('link', { name: 'Expenses', exact: true }).first()).toBeVisible();
    });

    test('an Employee (own scope only) is still sent to My expenses, not the queue', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/expenses');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.expenses\.view/).first()).toBeVisible();
    });

    test('an HR Executive with no expense scope is refused', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/expenses');
        await expect(page.getByText('Access denied')).toBeVisible();
    });
});
