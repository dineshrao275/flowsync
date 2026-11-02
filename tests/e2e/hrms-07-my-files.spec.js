import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession } from './helpers-workforce';

const RUN = Date.now().toString(36).slice(-5);
const TITLE = `QA-WF-Mine ${RUN}`;
const TITLE_SENSITIVE = `QA-WF-MineSens ${RUN}`;
const pdf = (name) => ({ name, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n') });

test.setTimeout(120_000);

async function openMine(page) {
    await page.goto('/app/hrms/documents/mine');
    await expect(page.getByRole('heading', { name: 'My files' }).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/\d+ documents?/).first()).toBeVisible({ timeout: 30_000 });
}

test.describe.serial('HRMS tab 7 — My files', () => {
    test('Employee: sees their files with no verify/reject/delete controls', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        const errs = [];
        page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) errs.push(`${r.status()} ${r.url()}`); });
        await openMine(page);
        await expect(page.getByRole('button', { name: 'Verify' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Reject' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Delete' })).toHaveCount(0);
        expect(errs).toEqual([]);
    });

    test('Employee: a disallowed file type is refused and not listed', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openMine(page);
        await page.getByRole('button', { name: 'Upload a file' }).click();
        const m = page.getByRole('dialog').filter({ hasText: 'Upload a file' }).last();
        await m.getByPlaceholder('Passport').fill(`QA-WF-Bad ${RUN}`);
        await m.locator('select').selectOption({ label: 'Bank Proof' });
        await m.locator('input[type=file]').setInputFiles({ name: 'run.exe', mimeType: 'application/x-msdownload', buffer: Buffer.from('MZ-no') });
        await m.getByRole('button', { name: /^Upload/ }).last().click();
        await expect(page.getByText(/not allowed|type|unsupported|invalid|must be/i).first()).toBeVisible({ timeout: 20_000 });
        await page.goto('/app/hrms/documents/mine');
        await expect(page.getByText(`QA-WF-Bad ${RUN}`)).toHaveCount(0);
    });

    async function uploadMine(page, title, typeLabel) {
        await page.getByRole('button', { name: 'Upload a file' }).click();
        const m = page.getByRole('dialog').filter({ hasText: 'Upload a file' }).last();
        await m.getByPlaceholder('Passport').fill(title);
        await m.locator('select').selectOption({ label: typeLabel });
        await m.locator('input[type=file]').setInputFiles(pdf('mine.pdf'));
        await m.getByRole('button', { name: /^Upload/ }).last().click();
        await expect(page.getByText('File uploaded. HR will review it.')).toBeVisible({ timeout: 30_000 });
    }

    test('Employee: uploads a non-sensitive file; it is listed Pending and persists', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openMine(page);
        await uploadMine(page, TITLE, 'Educational Certificate');
        await page.reload();
        const row = page.getByRole('row').filter({ hasText: TITLE });
        await expect(row).toBeVisible({ timeout: 30_000 });
        await expect(row).toContainText('Pending');
    });

    // Valid rule (D2.8): a sensitive type makes the row confidential, and the policy says being the person
    // in the file does not grant view_sensitive, so the owner does not see their own Bank Proof listed.
    test('Employee: a sensitive-type upload is accepted but not listed back to its owner', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openMine(page);
        await uploadMine(page, TITLE_SENSITIVE, 'Bank Proof');
        await page.reload();
        await expect(page.getByText(/\d+ documents?/).first()).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(TITLE_SENSITIVE)).toHaveCount(0);
    });

    test('integration: the admin sees those files under the employee (incl. the confidential one), then deletes them (cleanup)', async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/documents');
        await expect(page.getByText(/\d+ documents?/).first()).toBeVisible({ timeout: 30_000 });
        for (const title of [TITLE, TITLE_SENSITIVE, 'QA-WF-Probe']) {
            await page.getByPlaceholder('Title or file name').fill(title);
            const row = page.getByRole('row').filter({ hasText: title });
            if (title === 'QA-WF-Probe' && !(await row.count())) continue;
            await expect(row.first()).toBeVisible({ timeout: 30_000 });
            await expect(row.first()).toContainText('QA Employee');
            for (let n = await row.count(); n > 0; n -= 1) {
                await row.first().getByRole('button', { name: 'Delete' }).click();
                const confirm = page.getByRole('dialog').filter({ hasText: /delete/i }).last();
                if (await confirm.count()) await confirm.getByRole('button', { name: /delete|confirm|yes/i }).last().click();
                await expect(row).toHaveCount(n - 1, { timeout: 30_000 });
            }
        }
    });

    test('admin without an employee record gets no upload button', async ({ page }) => {
        await useSession(page);
        await openMine(page);
        await expect(page.getByRole('button', { name: 'Upload a file' })).toHaveCount(0);
    });
});
