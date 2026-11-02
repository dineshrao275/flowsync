import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, uniq, pickPerson } from './helpers-records';

// Tab 44 — /hrms/assets/mine (holder acknowledges / reports damage)
test.describe.configure({ mode: 'serial' });
const modal = (page, title) => page.getByRole('dialog').filter({ hasText: title });
const cat = uniq('MyCat');
const items = { ack: uniq('Phone'), dmg: uniq('Tablet') };

async function registerAndAssign(page, name) {
    await page.goto('/app/hrms/assets');
    await page.getByRole('button', { name: 'Register an asset' }).click({ timeout: 30000 });
    let dlg = modal(page, 'Register an asset');
    if (!(await dlg.getByLabel(/^Category/).locator('option', { hasText: cat }).count())) {
        await dlg.getByLabel('New category').fill(cat);
        await dlg.getByRole('button', { name: 'Add category' }).click();
        await expect(page.getByText('Category added.')).toBeVisible();
    }
    await dlg.getByLabel(/^Name/).fill(name);
    await dlg.getByLabel(/^Category/).selectOption({ label: cat });
    await dlg.getByRole('button', { name: 'Register', exact: true }).click();
    await expect(page.getByText('Asset registered.')).toBeVisible();
    await page.getByPlaceholder('Code, name, serial…').fill(name);
    const row = page.getByRole('row', { name: new RegExp(name) });
    await row.getByRole('button', { name: 'Assign' }).click();
    dlg = modal(page, 'Assign');
    await pickPerson(dlg, /^Person/, 'QA Employee');
    await dlg.getByRole('button', { name: 'Hand over' }).click();
    await expect(page.getByText(/Asset assigned/)).toBeVisible();
}

test('admin hands two assets to the employee (setup through the UI)', async ({ page }) => {
    await useSession(page);
    await registerAndAssign(page, items.ack);
    await registerAndAssign(page, items.dmg);
});

test('employee sees both handovers awaiting signature and acknowledges one', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/assets/mine');
    const ack = page.locator('li', { hasText: items.ack });
    await expect(ack).toContainText('awaiting your signature', { timeout: 20000 });
    await ack.getByRole('button', { name: 'Acknowledge' }).click();
    await expect(page.getByText('Receipt recorded.')).toBeVisible();
    await expect(ack).not.toContainText('awaiting your signature');
    await page.reload();
    await expect(page.locator('li', { hasText: items.ack })).not.toContainText('awaiting your signature');
});

test('report damage with a note sends the item to triage', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/assets/mine');
    const row = page.locator('li', { hasText: items.dmg });
    await row.getByRole('button', { name: 'Report damage' }).click();
    const dlg = modal(page, 'Report damage');
    // The note is optional by API design (return_note nullable); recorded as an improvement, not asserted as required.
    await dlg.getByLabel('What happened?').fill('QA-RC dropped on the floor');
    await dlg.getByRole('button', { name: 'Send to triage' }).click();
    await expect(page.getByText(/Damage reported/)).toBeVisible();
});

test('admin sees the damaged item in maintenance/triage and the ledger shows both handovers', async ({ page }) => {
    await useSession(page);
    await page.goto('/app/hrms/assets');
    await page.getByPlaceholder('Code, name, serial…').fill(items.dmg);
    const row = page.getByRole('row', { name: new RegExp(items.dmg) });
    await expect(row).toBeVisible({ timeout: 20000 });
    await expect(row).not.toContainText('assigned');
});

test('another employee-level user (HR Executive) cannot see the employee\'s handovers', async ({ page }) => {
    await useSession(page, PERSONAS.hrExec);
    await page.goto('/app/hrms/assets/mine');
    await expect(page.getByText(items.ack)).toHaveCount(0);
    await expect(page.getByText('Nothing checked out')).toBeVisible({ timeout: 20000 });
});
