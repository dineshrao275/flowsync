import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, uniq, pickPerson } from './helpers-records';

// Tab 41 — /hrms/letters (templates with merge fields, generate a PDF filed in the employee's documents)
test.describe.configure({ mode: 'serial' });
const tplName = uniq('Letter');
const renamed = `${tplName}-v2`;
const tplRow = (page, n) => page.locator('li', { hasText: n });
const modal = (page) => page.getByRole('dialog').filter({ hasText: /template/i }).last();
let slug = 'experience_letter';

test.describe('Letters — admin (documents.manage)', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/letters');
        await expect(page.getByRole('button', { name: 'New template' })).toBeVisible({ timeout: 30000 });
    });

    test('starter templates: adding them twice does not duplicate them', async ({ page }) => {
        await page.getByRole('button', { name: 'Add starter templates' }).click();
        await page.waitForTimeout(1500);
        const count = await page.locator('main li').filter({ has: page.getByRole('button', { name: 'Edit' }) }).count();
        expect(count).toBeGreaterThan(0);
        await page.getByRole('button', { name: 'Add starter templates' }).click();
        await page.waitForTimeout(1500);
        expect(await page.locator('main li').filter({ has: page.getByRole('button', { name: 'Edit' }) }).count()).toBe(count);
        // learn a valid document-type slug from a starter
        await page.locator('main li').filter({ has: page.getByRole('button', { name: 'Edit' }) }).first().getByRole('button', { name: 'Edit' }).click();
        slug = await modal(page).getByLabel(/^Document type/).inputValue();
        expect(slug).not.toBe('');
    });

    test('name and document type are required; an unknown document type and an empty body are refused with visible messages', async ({ page }) => {
        await page.getByRole('button', { name: 'New template' }).click();
        const dlg = modal(page);
        let posted = false;
        page.on('request', (r) => { if (r.method() === 'POST' && r.url().endsWith('/api/hrms/letter-templates')) posted = true; });
        await dlg.getByRole('button', { name: 'Save template' }).click();
        await page.waitForTimeout(500);
        expect(posted).toBe(false);
        await dlg.getByLabel(/^Name/).fill(tplName);
        await dlg.getByLabel(/^Document type/).fill('not-a-real-type-zzz');
        await dlg.getByLabel('Letter text').fill('Hello {{employee_name}}');
        await dlg.getByRole('button', { name: 'Save template' }).click();
        await expect(page.getByText('Template saved.')).toHaveCount(0);
        await expect(dlg.locator('p.text-red-600').first()).toBeVisible({ timeout: 15000 });
        await dlg.getByLabel(/^Document type/).fill(slug);
        await dlg.getByLabel('Letter text').fill('');
        await dlg.getByRole('button', { name: 'Save template' }).click();
        await expect(page.getByText('Template saved.')).toHaveCount(0);
        await expect(dlg.locator('p.text-red-600').first()).toBeVisible({ timeout: 15000 });
    });

    test('create a template with a custom merge field; it is listed and survives a reload', async ({ page }) => {
        await page.getByRole('button', { name: 'New template' }).click();
        const dlg = modal(page);
        await dlg.getByLabel(/^Name/).fill(tplName);
        await dlg.getByLabel(/^Document type/).fill(slug);
        await dlg.getByLabel('Letter text').fill('Dear {{employee_name}},\n\nQA-RC note: {{custom_note}}\n');
        await dlg.getByRole('button', { name: 'Save template' }).click();
        await expect(page.getByText('Template saved.').first()).toBeVisible();
        await page.reload();
        await expect(tplRow(page, tplName)).toBeVisible({ timeout: 30000 });
    });

    test('generate: the custom field is asked for (required), the PDF is filed in the employee\'s documents', async ({ page }) => {
        await page.getByLabel('Template').selectOption({ label: tplName });
        const gen = page.getByRole('button', { name: 'Generate PDF' });
        await expect(gen).toBeDisabled();
        await pickPerson(page.locator('main'), 'Employee', 'QA Employee');
        await expect(gen).toBeEnabled();
        const custom = page.getByLabel('custom note');
        await expect(custom).toBeVisible();
        let posted = false;
        page.on('request', (r) => { if (r.method() === 'POST' && /\/letters$/.test(r.url())) posted = true; });
        await gen.click();
        await page.waitForTimeout(500);
        expect(posted).toBe(false); // native required blocks the empty custom field
        await custom.fill('QA-RC all good');
        await gen.click();
        await expect(page.getByText('Letter generated and filed.').first()).toBeVisible({ timeout: 30000 });
        await page.goto('/app/hrms/documents');
        await page.getByPlaceholder(/Search|title/i).first().fill(tplName).catch(() => {});
        await expect(page.getByText(new RegExp(tplName)).first()).toBeVisible({ timeout: 30000 });
    });

    test('edit renames the template and it persists', async ({ page }) => {
        await tplRow(page, tplName).getByRole('button', { name: 'Edit' }).click();
        await modal(page).getByLabel(/^Name/).fill(renamed);
        await modal(page).getByRole('button', { name: 'Save template' }).click();
        await expect(page.getByText('Template saved.').first()).toBeVisible();
        await page.reload();
        await expect(tplRow(page, renamed)).toBeVisible({ timeout: 30000 });
    });

    test('delete asks for confirmation (Cancel keeps it) and then removes only this template', async ({ page }) => {
        await tplRow(page, renamed).getByRole('button', { name: 'Delete' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /delete/i }).last();
        await expect(dlg).toBeVisible({ timeout: 10000 }); // RC-9: used to delete on the first click
        await dlg.getByRole('button', { name: 'Cancel' }).click();
        await expect(tplRow(page, renamed)).toBeVisible();
        await tplRow(page, renamed).getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: /delete/i }).last().getByRole('button', { name: 'Delete' }).click();
        await expect(tplRow(page, renamed)).toHaveCount(0);
        await page.reload();
        await expect(page.getByRole('button', { name: 'New template' })).toBeVisible({ timeout: 30000 });
        await expect(tplRow(page, renamed)).toHaveCount(0);
    });
});

test('Employee is refused the page and the API', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/letters');
    await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30000 });
    await expect(page.getByText(/hrms\.documents\.manage/)).toBeVisible();
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    expect((await page.request.get('/api/hrms/letter-templates', { headers: { Accept: 'application/json' } })).status()).toBe(403);
    expect((await page.request.post('/api/hrms/letter-templates', { data: { name: 'x' }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } })).status()).toBe(403);
});
