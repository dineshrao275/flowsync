import { useSession, login, TENANT_ADMIN, PERSONAS } from './helpers';

export const RC = 'QA-RC-';
export const uniq = (s) => `${RC}${s}-${Date.now().toString(36)}`;
export { useSession, TENANT_ADMIN, PERSONAS };

// Collect failing /api responses seen while a page is used (status >= 400).
export function trackApiErrors(page) {
    const errs = [];
    page.on('response', (r) => {
        if (r.url().includes('/api/') && r.status() >= 400) errs.push(`${r.status()} ${r.request().method()} ${r.url().split('/api/')[1].split('?')[0]}`);
    });
    return errs;
}

// PersonSelect/MemberPicker is a combobox: type to filter, then click the option.
export async function pickPerson(scope, label, who) {
    const box = scope.getByLabel(label);
    await box.click();
    await box.fill(who);
    await scope.page().getByRole('option', { name: new RegExp(who) }).first().click();
}

import fs from 'node:fs';

// The leaver is unique per run (an approved resignation cannot be undone). The run id is kept in a
// file so specs 32 and 33 share it; delete tests/e2e/.auth/rc-run.txt to start a fresh leaver.
const RUN_FILE = 'tests/e2e/.auth/rc-run.txt';
function runId() {
    if (!fs.existsSync(RUN_FILE)) {
        fs.mkdirSync('tests/e2e/.auth', { recursive: true });
        fs.writeFileSync(RUN_FILE, Date.now().toString(36));
    }
    return fs.readFileSync(RUN_FILE, 'utf8').trim();
}
export const RESIGNER = { name: `QA-RC Resigner ${runId()}`, email: `qa.rc.resigner.${runId()}@globex.test`, password: 'password', role: 'Employee' };

// Create a user through the real Add user form (idempotent).
export async function ensureUser(page, p) {
    await page.goto('/app/users');
    await page.getByPlaceholder(/Search by name or email/).fill(p.email);
    await page.waitForTimeout(1500);
    if (await page.getByText(p.email, { exact: true }).count()) return false;
    await page.getByRole('button', { name: 'Add user' }).first().click();
    await page.getByPlaceholder('Jane Doe').fill(p.name);
    await page.getByPlaceholder('jane@company.com').fill(p.email);
    await page.getByPlaceholder('At least 8 characters').fill(p.password);
    await page.getByPlaceholder('Repeat password').fill(p.password);
    await page.getByRole('button', { name: new RegExp(`\\+?\\s*${p.role}$`) }).first().click();
    await page.getByRole('button', { name: 'Add user' }).last().click();
    await page.getByText(p.email).first().waitFor({ timeout: 20000 });
    return true;
}

export const isoDate = (offsetDays) => new Date(Date.now() + offsetDays * 86400000).toISOString().slice(0, 10);

// A page in its OWN browser context signed in as `persona` (swapping cookies inside one context
// leaves the previous user's session tokens behind and the SPA never finishes loading).
export async function personaPage(browser, persona) {
    const ctx = await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
    const page = await ctx.newPage();
    await useSession(page, persona);
    // A saved session can expire; re-authenticate once (one real sign-in) and refresh the cookie file.
    const me = await page.request.get('/api/auth/me', { headers: { Accept: 'application/json' } });
    if (me.status() === 401) {
        await ctx.clearCookies();
        await login(page, persona);
        fs.mkdirSync('tests/e2e/.auth', { recursive: true });
        fs.writeFileSync(`tests/e2e/.auth/${persona.email}.json`, JSON.stringify(await ctx.cookies()));
    }
    return page;
}

// Upload a small text document for an employee through the real Documents page.
export async function uploadDocument(page, employee, title, type = 'Experience Letter') {
    await page.goto('/app/hrms/documents');
    await page.getByRole('button', { name: 'Upload document' }).click({ timeout: 60000 });
    const dlg = page.getByRole('dialog').filter({ hasText: 'Upload a file' });
    const who = dlg.locator('input[role=combobox]').first();
    await who.click();
    await who.fill(employee);
    await page.getByRole('option', { name: new RegExp(employee) }).first().click();
    await dlg.getByLabel('Title').fill(title);
    await dlg.getByLabel('Document type').selectOption({ label: type });
    await dlg.locator('#document-file').setInputFiles({ name: `${title}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`QA-RC document ${title}`) });
    await dlg.getByRole('button', { name: 'Upload document' }).click();
    await page.getByText(title).first().waitFor({ timeout: 30000 });
}
