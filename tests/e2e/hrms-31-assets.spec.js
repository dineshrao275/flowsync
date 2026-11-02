import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, uniq, pickPerson } from './helpers-records';

// Tab 31 — /hrms/assets (register, assign, return, repair, detail, filters, permissions)
test.describe.configure({ mode: 'serial' });
const modal = (page, title) => page.getByRole('dialog').filter({ hasText: title });
const name = uniq('Laptop');
const serial = uniq('SN').toUpperCase();
const category = uniq('Cat');

async function register(page, n, s, opts = {}) {
    await page.getByRole('button', { name: 'Register an asset' }).click();
    const dlg = modal(page, 'Register an asset');
    await dlg.getByLabel(/^Name/).fill(n);
    await dlg.getByLabel(/^Category/).selectOption({ label: category });
    if (s) await dlg.getByLabel('Serial number').fill(s);
    await dlg.getByRole('button', { name: 'Register', exact: true }).click();
    return dlg;
}

test.describe('Assets — admin', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/assets');
        await expect(page.getByRole('button', { name: 'Register an asset' })).toBeVisible({ timeout: 25000 });
    });

    test('register requires name and category', async ({ page }) => {
        let posted = false;
        page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/api/hrms/assets')) posted = true; });
        await page.getByRole('button', { name: 'Register an asset' }).click();
        const dlg = modal(page, 'Register an asset');
        await dlg.getByRole('button', { name: 'Register', exact: true }).click();
        await page.waitForTimeout(500);
        expect(posted).toBe(false);
        await expect(dlg).toBeVisible();
        // native `required`: the browser blocks the submit, so no request is sent
        expect(await dlg.getByLabel(/^Name/).evaluate((el) => el.validity.valueMissing)).toBe(true);
        expect(await dlg.getByLabel(/^Category/).evaluate((el) => el.validity.valueMissing)).toBe(true);
        await expect(page.getByText('Asset registered.')).toHaveCount(0);
    });

    test('a tenant with no categories can create one from the register dialog (BUG-AS1)', async ({ page }) => {
        await page.getByRole('button', { name: 'Register an asset' }).click();
        const dlg = modal(page, 'Register an asset');
        const cat = dlg.getByLabel(/^Category/);
        if ((await cat.locator('option').count()) === 1) {
            await expect(dlg.getByText(/No asset categories yet/)).toBeVisible();
        }
        await dlg.getByLabel('New category').fill(category);
        await dlg.getByRole('button', { name: 'Add category' }).click();
        await expect(page.getByText('Category added.')).toBeVisible();
        await expect(cat).toContainText(category);
    });

    test('register creates an asset that is listed and searchable', async ({ page }) => {
        await register(page, name, serial);
        await expect(page.getByText('Asset registered.')).toBeVisible();
        await page.getByPlaceholder('Code, name, serial…').fill(serial);
        await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible();
    });

    test('duplicate serial number is refused', async ({ page }) => {
        const dlg = await register(page, uniq('Dup'), serial);
        await expect(page.getByText('Asset registered.')).toHaveCount(0);
        await expect(dlg).toBeVisible();
    });

    test('status filter narrows the list', async ({ page }) => {
        await page.getByPlaceholder('Code, name, serial…').fill(serial);
        await page.getByLabel('Status').selectOption('assigned');
        await expect(page.getByRole('row', { name: new RegExp(name) })).toHaveCount(0, { timeout: 15000 });
        await page.getByLabel('Status').selectOption('available');
        await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible();
    });

    test('assign hands the asset to an employee and shows the holder', async ({ page }) => {
        await page.getByPlaceholder('Code, name, serial…').fill(serial);
        const row = page.getByRole('row', { name: new RegExp(name) });
        await row.getByRole('button', { name: 'Assign' }).click();
        const dlg = modal(page, 'Assign');
        await pickPerson(dlg, /^Person/, 'QA Employee');
        await dlg.getByRole('button', { name: 'Hand over' }).click();
        await expect(page.getByText(/Asset assigned/)).toBeVisible();
        await expect(row).toContainText('QA Employee');
        await expect(row).toContainText('assigned');
    });

    test('detail drawer shows the handover ledger', async ({ page }) => {
        await page.getByPlaceholder('Code, name, serial…').fill(serial);
        await page.getByRole('row', { name: new RegExp(name) }).getByRole('button').first().click();
        await expect(page.getByText('Handover ledger')).toBeVisible();
        await expect(page.getByText(/awaiting signature/)).toBeVisible();
    });
});

test.describe('Assets — employee holder (integration with /hrms/assets/mine)', () => {
    test('employee sees the assigned asset on My assets, not the register', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/assets/mine');
        await expect(page.getByText(name)).toBeVisible();
        await page.goto('/app/hrms/assets');
        await expect(page.getByText('Access denied')).toBeVisible();
        // authorization is also enforced server-side, independent of the route gate
        const api = await page.request.get('/api/hrms/assets', { headers: { Accept: 'application/json' } });
        expect(api.status()).toBe(403);
        const write = await page.request.post('/api/hrms/assets/categories', { data: { name: 'QA-RC-nope' }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '') } });
        expect(write.status()).toBe(403);
    });
});

test.describe('Assets — return and repair (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/assets');
        await page.getByPlaceholder('Code, name, serial…').fill(serial);
    });

    test('return takes the asset back', async ({ page }) => {
        const row = page.getByRole('row', { name: new RegExp(name) });
        await row.getByRole('button', { name: 'Return' }).click();
        await modal(page, 'Take back').getByRole('button', { name: 'Take back' }).click();
        await expect(page.getByText('Asset returned.')).toBeVisible();
        await expect(row).toContainText('available');
    });

    test('repair requires a description and parks the asset in maintenance', async ({ page }) => {
        const row = page.getByRole('row', { name: new RegExp(name) });
        await row.getByRole('button', { name: 'Repair' }).click();
        const dlg = modal(page, 'Repair');
        await dlg.getByRole('button', { name: 'Open repair' }).click();
        await expect(page.getByText(/Repair opened/)).toHaveCount(0);
        await dlg.getByLabel(/^Description/).fill('QA-RC screen flicker');
        await dlg.getByLabel(/^Performed at/).fill('2026-10-01');
        await dlg.getByRole('button', { name: 'Open repair' }).click();
        await expect(page.getByText(/Repair opened/)).toBeVisible();
        await expect(row).toContainText('maintenance');
    });
});
