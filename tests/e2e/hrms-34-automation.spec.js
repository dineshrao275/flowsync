import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, uniq } from './helpers-records';

// Tab 34 — /hrms/automation (HR rules that create tasks in a TMS project)
test.describe.configure({ mode: 'serial' });
const modal = (page) => page.getByRole('dialog').filter({ hasText: /HR automation rule|Edit rule/ });
const name = uniq('Rule');
const renamed = `${name}-edited`;
const ruleRow = (page, n) => page.locator('li', { hasText: n });

test.describe('HR automation — admin', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/automation');
        await expect(page.getByRole('button', { name: 'Add rule' })).toBeVisible({ timeout: 30000 });
    });

    test('Save stays disabled until a name and a project are chosen', async ({ page }) => {
        await page.getByRole('button', { name: 'Add rule' }).click();
        const dlg = modal(page);
        const save = dlg.getByRole('button', { name: 'Save' });
        await expect(save).toBeDisabled();
        await dlg.getByLabel(/^Rule name/).fill(name);
        await expect(save).toBeDisabled();
        await dlg.getByLabel(/^Create tasks in/).selectOption({ label: 'Project 1' });
        await expect(save).toBeEnabled();
    });

    test('a rule with no task titles is refused by the server and the message is shown', async ({ page }) => {
        await page.getByRole('button', { name: 'Add rule' }).click();
        const dlg = modal(page);
        await dlg.getByLabel(/^Rule name/).fill(name);
        await dlg.getByLabel(/^Create tasks in/).selectOption({ label: 'Project 1' });
        await dlg.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Rule saved.')).toHaveCount(0);
        await expect(dlg.locator('p.text-red-600, [role=alert]').first()).toBeVisible({ timeout: 15000 });
    });

    test('due-in days is bounded 0-365', async ({ page }) => {
        await page.getByRole('button', { name: 'Add rule' }).click();
        const due = modal(page).getByLabel(/^Due in/);
        await due.fill('366');
        expect(await due.evaluate((el) => el.validity.rangeOverflow)).toBe(true);
        await due.fill('-1');
        expect(await due.evaluate((el) => el.validity.rangeUnderflow)).toBe(true);
    });

    test('create: the rule is saved, listed with its task count and project, and survives a reload', async ({ page }) => {
        await page.getByRole('button', { name: 'Add rule' }).click();
        const dlg = modal(page);
        await dlg.getByLabel(/^Rule name/).fill(name);
        await dlg.getByLabel(/^Create tasks in/).selectOption({ label: 'Project 1' });
        await dlg.getByLabel(/^Task title/).fill('QA-RC welcome {employee} on {date}');
        await dlg.getByLabel(/^Due in/).fill('3');
        await dlg.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Rule saved.')).toBeVisible();
        await expect(ruleRow(page, name)).toContainText('creates 1 task(s) in Project 1');
        await expect(ruleRow(page, name)).toContainText('Ran 0 time(s)');
        await page.reload();
        await expect(ruleRow(page, name)).toBeVisible({ timeout: 30000 });
    });

    test('edit: rename and switch the rule off; both persist', async ({ page }) => {
        await ruleRow(page, name).getByRole('button', { name: 'Edit' }).click();
        const dlg = modal(page);
        await dlg.getByLabel(/^Rule name/).fill(renamed);
        await dlg.getByRole('switch', { name: 'Rule is on' }).click();
        await dlg.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Rule saved.')).toBeVisible();
        await page.reload();
        await expect(ruleRow(page, renamed)).toContainText('Off', { timeout: 30000 });
        await expect(ruleRow(page, name + '-edited')).toBeVisible();
    });

    test('delete asks for confirmation (Cancel keeps it), then removes only this rule', async ({ page }) => {
        await ruleRow(page, renamed).getByRole('button', { name: 'Delete' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'Delete this rule?' });
        await dlg.getByRole('button', { name: 'Cancel' }).click();
        await expect(ruleRow(page, renamed)).toBeVisible();
        await ruleRow(page, renamed).getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete this rule?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(ruleRow(page, renamed)).toHaveCount(0);
        await page.reload();
        await expect(page.getByRole('button', { name: 'Add rule' })).toBeVisible({ timeout: 30000 });
        await expect(ruleRow(page, renamed)).toHaveCount(0);
    });
});

test('Employee is refused the page and the API', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/automation');
    await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30000 });
    await expect(page.getByText(/hrms\.onboarding\.manage/)).toBeVisible();
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const list = await page.request.get('/api/hrms/automation-rules', { headers: { Accept: 'application/json' } });
    expect(list.status()).toBe(403);
    const make = await page.request.post('/api/hrms/automation-rules', { data: { name: 'QA-RC nope' }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });
    expect(make.status()).toBe(403);
});
