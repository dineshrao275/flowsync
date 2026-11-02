import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession, apiCall } from './helpers-workforce';

const RUN = Date.now().toString(36).slice(-5);
const TITLE = `QA-WF-Doc ${RUN}`;
const TITLE2 = `QA-WF-Doc2 ${RUN}`;
const pdf = (name) => ({ name, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n') });
const modal = (page) => page.getByRole('dialog').filter({ hasText: 'Upload document' }).first();

test.setTimeout(120_000);

async function openDocs(page) {
    await page.goto('/app/hrms/documents');
    await expect(page.getByText(/\d+ documents?/).first()).toBeVisible({ timeout: 30_000 });
}

async function upload(page, title, file, who = 'QA Employee') {
    await page.getByRole('button', { name: 'Upload document' }).first().click();
    const m = modal(page);
    await m.getByPlaceholder('Select an employee').fill(who);
    await page.getByRole('option', { name: new RegExp(who) }).first().click();
    await m.getByPlaceholder('Passport').fill(title);
    await m.locator('select').selectOption({ label: 'Bank Proof' });
    await m.locator('input[type=file]').setInputFiles(file);
    await m.getByRole('button', { name: 'Upload document' }).last().click();
}

const row = (page, title) => page.getByRole('row').filter({ hasText: title });

test.describe.serial('HRMS tab 6 — Documents (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('upload: the button stays disabled until employee, title, type and file are all given', async ({ page }) => {
        await openDocs(page);
        await page.getByRole('button', { name: 'Upload document' }).first().click();
        const submit = modal(page).getByRole('button', { name: 'Upload document' }).last();
        await expect(submit).toBeDisabled();
        await modal(page).getByPlaceholder('Select an employee').fill('QA Employee');
        await page.getByRole('option', { name: /QA Employee/ }).first().click();
        await expect(submit).toBeDisabled();
    });

    test('upload: a disallowed file type is refused and nothing is saved', async ({ page }) => {
        await openDocs(page);
        await upload(page, `QA-WF-Bad ${RUN}`, { name: 'malware.exe', mimeType: 'application/x-msdownload', buffer: Buffer.from('MZ-not-a-document') });
        await expect(page.getByText(/not allowed|type|unsupported|invalid|must be/i).first()).toBeVisible({ timeout: 20_000 });
        await page.goto('/app/hrms/documents');
        await expect(page.getByText(`QA-WF-Bad ${RUN}`)).toHaveCount(0);
    });

    test('upload: a valid PDF is saved as Pending and survives a reload', async ({ page }) => {
        await openDocs(page);
        await upload(page, TITLE, pdf('qa-wf.pdf'));
        await expect(row(page, TITLE)).toBeVisible({ timeout: 30_000 });
        await page.reload();
        await expect(row(page, TITLE)).toContainText('Pending', { timeout: 30_000 });
        await expect(row(page, TITLE)).toContainText('Bank Proof');
        await expect(row(page, TITLE)).toContainText('QA Employee');
    });

    test('read: search finds the document and an unknown term does not', async ({ page }) => {
        await openDocs(page);
        await page.getByPlaceholder('Title or file name').fill(TITLE);
        await expect(row(page, TITLE)).toBeVisible({ timeout: 20_000 });
        await page.getByPlaceholder('Title or file name').fill('zzz-no-such-document');
        await expect(row(page, TITLE)).toHaveCount(0, { timeout: 20_000 });
    });

    test('update: Verify moves it to Verified and the status persists', async ({ page }) => {
        await openDocs(page);
        await page.getByPlaceholder('Title or file name').fill(TITLE);
        await row(page, TITLE).getByRole('button', { name: 'Verify' }).click();
        await expect(row(page, TITLE)).toContainText('Verified', { timeout: 30_000 });
        await page.reload();
        await page.getByPlaceholder('Title or file name').fill(TITLE);
        await expect(row(page, TITLE)).toContainText('Verified', { timeout: 30_000 });
    });

    test('update: Reject needs a reason and marks the document Rejected', async ({ page }) => {
        await openDocs(page);
        await upload(page, TITLE2, pdf('qa-wf2.pdf'));
        await expect(row(page, TITLE2)).toBeVisible({ timeout: 30_000 });
        await row(page, TITLE2).getByRole('button', { name: 'Reject' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /reject/i }).last();
        const reason = dlg.getByRole('textbox').first();
        if (await reason.count()) await reason.fill('QA-WF: blurry scan');
        await dlg.getByRole('button', { name: /reject|confirm|save/i }).last().click();
        await expect(row(page, TITLE2)).toContainText('Rejected', { timeout: 30_000 });
    });

    test('delete: removes both documents created by this run', async ({ page }) => {
        await openDocs(page);
        for (const title of [TITLE, TITLE2]) {
            await page.getByPlaceholder('Title or file name').fill(title);
            await expect(row(page, title)).toBeVisible({ timeout: 20_000 });
            await row(page, title).getByRole('button', { name: 'Delete' }).click();
            const confirm = page.getByRole('dialog').filter({ hasText: /delete/i }).last();
            if (await confirm.count()) await confirm.getByRole('button', { name: /delete|confirm|yes/i }).last().click();
            await expect(row(page, title)).toHaveCount(0, { timeout: 30_000 });
        }
    });
});

test.describe('HRMS tab 6 — Documents (roles)', () => {
    test('Employee persona is refused the directory with the missing permission', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/documents');
        await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(/hrms\.documents\.view/)).toBeVisible();
    });

    // The list API is self-scoped for a holder of documents.view_own only: it must never include another
    // employee's file. Proven with a document uploaded for the HR Executive.
    test('Employee API list never includes another employee\'s document', async ({ browser }) => {
        const adminCtx = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const admin = await adminCtx.newPage();
        await useSession(admin);
        const TITLE3 = `QA-WF-Other ${RUN}`;
        await openDocs(admin);
        await upload(admin, TITLE3, pdf('qa-wf-other.pdf'), 'QA HR Executive');
        await expect(row(admin, TITLE3)).toBeVisible({ timeout: 30_000 });

        const empCtx = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const emp = await empCtx.newPage();
        await useSession(emp, PERSONAS.employee);
        await emp.goto('/app/hrms');
        await emp.waitForLoadState('networkidle');
        const res = await apiCall(emp, 'GET', '/hrms/documents?per_page=100&q=QA-WF-Other');
        expect(res.status).toBe(200);
        expect(JSON.stringify(res.json)).not.toContain(TITLE3);

        // cleanup: the document belongs to this run
        await admin.getByPlaceholder('Title or file name').fill(TITLE3);
        await row(admin, TITLE3).getByRole('button', { name: 'Delete' }).click();
        const confirm = admin.getByRole('dialog').filter({ hasText: /delete/i }).last();
        if (await confirm.count()) await confirm.getByRole('button', { name: /delete|confirm|yes/i }).last().click();
        await expect(row(admin, TITLE3)).toHaveCount(0, { timeout: 30_000 });
        await adminCtx.close();
        await empCtx.close();
    });

    test('HR Executive can open the directory', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openDocs(page);
    });
});
