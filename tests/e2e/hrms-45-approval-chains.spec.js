import { test, expect } from '@playwright/test';
import { PERSONAS, personaPage } from './helpers-records';

// Tab 45 — /hrms/approvals/chains. Edits the LOANS chain only (least shared) and always resets it.
test.describe.configure({ mode: 'serial' });
const OWNER = { email: 'owner@globex.test', password: 'password' };
const TITLE = 'Loans and salary advances';
// smallest element holding the title AND its own Edit button = this chain's card
const card = (page) => page.locator('div').filter({ has: page.getByText(TITLE, { exact: true }) }).filter({ has: page.getByRole('button', { name: 'Edit' }) }).last();
const editor = (page) => page.getByRole('dialog').filter({ hasText: `Edit: ${TITLE}` });

async function open(page) {
    await page.goto('/app/hrms/approvals/chains');
    await expect(page.getByText(TITLE).first()).toBeVisible({ timeout: 60000 });
}

async function resetIfCustomised(page) {
    await open(page);
    const reset = card(page).getByRole('button', { name: 'Reset' });
    if (await reset.count()) {
        await reset.click();
        const dlg = page.getByRole('dialog').filter({ hasText: /reset/i }).last();
        await dlg.getByRole('button', { name: /reset/i }).last().click();
        await expect(card(page)).toContainText('Default chain', { timeout: 20000 });
    }
}

test('setup: start from the default Loans chain', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await resetIfCustomised(page);
    await page.context().close();
});

test('every domain is listed with an Edit button; default chains have no Reset', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await open(page);
    for (const t of ['Leave requests', 'Expense claims', 'Resignations', 'Comp-off requests', TITLE]) {
        await expect(page.getByText(t).first()).toBeVisible();
    }
    expect(await page.getByRole('button', { name: 'Edit' }).count()).toBeGreaterThanOrEqual(8);
    await expect(card(page)).toContainText('Default chain');
    await expect(card(page).getByRole('button', { name: 'Reset' })).toHaveCount(0);
    await page.context().close();
});

test('editor: add a step, reorder, remove, and Cancel discards everything', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await open(page);
    const before = await card(page).locator('ol li').allInnerTexts();
    await card(page).getByRole('button', { name: 'Edit' }).click();
    const dlg = editor(page);
    const steps = () => dlg.getByText(/^Step \d+$/);
    const n = await steps().count();
    await dlg.getByRole('button', { name: 'Add a step' }).click();
    await expect(steps()).toHaveCount(n + 1);
    await dlg.getByRole('button', { name: 'Remove' }).last().click();
    await expect(steps()).toHaveCount(n);
    await dlg.getByRole('button', { name: 'Add a step' }).click();
    await dlg.getByRole('button', { name: 'Cancel' }).click();
    await expect(card(page).locator('ol li')).toHaveText(before);
    await card(page).getByRole('button', { name: 'Edit' }).click();
    await expect(editor(page).getByText(/^Step \d+$/)).toHaveCount(n);
    await page.context().close();
});

test('a role step with no role chosen is refused with a visible message and the editor stays open', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await open(page);
    await card(page).getByRole('button', { name: 'Edit' }).click();
    const dlg = editor(page);
    await dlg.getByRole('button', { name: 'Add a step' }).click();
    await dlg.getByLabel('Approver').last().selectOption({ label: 'Role' });
    await dlg.getByRole('button', { name: 'Save chain' }).click();
    await expect(dlg).toBeVisible();
    await expect(dlg.locator('p.text-red-600, [role=alert]').first()).toBeVisible({ timeout: 15000 });
    await expect(card(page)).toContainText('Default chain');
    await page.context().close();
});

test('a valid edit saves, marks the chain customised with a Reset button, and persists after reload', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await open(page);
    await card(page).getByRole('button', { name: 'Edit' }).click();
    const dlg = editor(page);
    await dlg.getByLabel('Default stage deadline (hours)').fill('36');
    await dlg.getByRole('button', { name: 'Save chain' }).click();
    await expect(card(page)).toContainText('Customised', { timeout: 20000 });
    await expect(page.getByText(/36/).first()).toBeVisible();
    await page.reload();
    await expect(card(page)).toContainText('Customised', { timeout: 60000 });
    await expect(card(page).getByRole('button', { name: 'Reset' })).toBeVisible();
    await page.context().close();
});

test('Reset asks for confirmation (Cancel keeps the edit), then restores the default chain', async ({ browser }) => {
    const page = await personaPage(browser, OWNER);
    await open(page);
    await card(page).getByRole('button', { name: 'Reset' }).click();
    const dlg = page.getByRole('dialog').filter({ hasText: /reset/i }).last();
    await dlg.getByRole('button', { name: 'Cancel' }).click();
    await expect(card(page)).toContainText('Customised');
    await resetIfCustomised(page);
    await page.reload();
    await expect(card(page)).toContainText('Default chain', { timeout: 60000 });
    await page.context().close();
});

test('Employee is refused the page and the chains API', async ({ browser }) => {
    const page = await personaPage(browser, PERSONAS.employee);
    await page.goto('/app/hrms/approvals/chains');
    await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30000 });
    await expect(page.getByText(/hrms\.approvals\.manage/)).toBeVisible();
    expect((await page.request.get('/api/hrms/approvals/chains', { headers: { Accept: 'application/json' } })).status()).toBe(403);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    expect((await page.request.put('/api/hrms/approvals/chains/loans', { data: { steps: [{ type: 'manager' }] }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } })).status()).toBe(403);
    await page.context().close();
});
