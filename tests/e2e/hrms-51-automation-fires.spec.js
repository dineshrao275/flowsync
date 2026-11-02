import { test, expect } from '@playwright/test';
import { useSession, uniq } from './helpers-records';

// A rule is only worth having if it fires: rule created in the UI, an employee created in the UI
// (domain event employee.created -> queue -> HrAutomationConsumer), and the task shows up in the project.
test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

const RULE = uniq('Fire');
const HIRE = `QA-FIRE Hire ${Date.now().toString(36)}`;
const ruleRow = (page) => page.locator('li', { hasText: RULE });

test('a rule creates its task in the project when an employee is created', async ({ page }) => {
    await useSession(page);

    await page.goto('/app/hrms/automation');
    await page.getByRole('button', { name: 'Add rule' }).click();
    const dlg = page.getByRole('dialog').filter({ hasText: /HR automation rule|Edit rule/ });
    await dlg.getByLabel(/^Rule name/).fill(RULE);
    await dlg.getByLabel(/^Create tasks in/).selectOption({ label: 'Project 1' });
    await dlg.getByLabel(/^Task title/).fill('QA-FIRE welcome {employee} on {date}');
    await dlg.getByLabel(/^Due in/).fill('3');
    await dlg.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Rule saved.')).toBeVisible();
    await expect(ruleRow(page)).toContainText('Ran 0 time(s)');

    await page.goto('/app/hrms/employees');
    await expect(page.getByText(/\d+ records?/)).toBeVisible({ timeout: 30_000 });
    await page.getByRole('button', { name: 'New employee' }).click();
    const create = page.getByRole('dialog').filter({ hasText: /optionally a sign-in account/ }).first();
    await create.getByPlaceholder('Full name').fill(HIRE);
    await create.getByPlaceholder('Designation').fill('QA Fire Tester');
    await create.getByRole('button', { name: 'Create employee' }).click();
    await expect(page.getByRole('link', { name: HIRE })).toBeVisible({ timeout: 30_000 });

    // The event is processed by the queue worker, so poll the rule's own run counter.
    await expect.poll(async () => {
        await page.goto('/app/hrms/automation');
        await expect(ruleRow(page)).toBeVisible({ timeout: 30_000 });
        return ruleRow(page).innerText();
    }, { timeout: 120_000, intervals: [3_000] }).toContain('Ran 1 time(s)');

    const found = await page.evaluate(async (q) => (await (await fetch(`/api/search/tasks?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })).text()), `QA-FIRE welcome ${HIRE}`);
    expect(found).toContain(`QA-FIRE welcome ${HIRE}`);

    // Tidy up what the product lets us remove: the rule (the employee stays, the task is a normal task).
    await page.goto('/app/hrms/automation');
    await ruleRow(page).getByRole('button', { name: /delete/i }).click();
    await page.getByRole('button', { name: /^(delete|confirm|yes)/i }).last().click();
    await expect(ruleRow(page)).toHaveCount(0, { timeout: 15_000 });
});
