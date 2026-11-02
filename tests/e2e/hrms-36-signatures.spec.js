import { test, expect } from '@playwright/test';
import { PERSONAS, uniq, uploadDocument, pickPerson, personaPage } from './helpers-records';

// Tab 36 — /hrms/signatures (request, sign with password re-auth, decline, cancel, public verification)
test.describe.configure({ mode: 'serial' });
const OWNER = { email: 'owner@globex.test', password: 'password' };
const docs = { sign: uniq('NDA'), decline: uniq('Policy'), cancel: uniq('Offer') };
const card = (page, title) => page.locator('li', { hasText: title });

test('setup: admin uploads three test documents for QA Employee through the Documents page', async ({ browser }) => {
    const admin = await personaPage(browser, OWNER);
    for (const title of Object.values(docs)) await uploadDocument(admin, 'QA Employee', title);
    await admin.context().close();
});

test('Send request stays disabled until a signer and a document are chosen; request is listed Waiting', async ({ browser }) => {
    const admin = await personaPage(browser, OWNER);
    await admin.goto('/app/hrms/signatures');
    const send = admin.getByRole('button', { name: 'Send request' });
    await expect(send).toBeDisabled({ timeout: 30000 });
    const signer = admin.locator('#sg-emp');
    await signer.click();
    await signer.fill('QA Employee');
    await admin.getByRole('option', { name: /QA Employee/ }).first().click();
    await expect(send).toBeDisabled();
    for (const title of Object.values(docs)) {
        await admin.getByLabel('Document').selectOption({ label: title });
        await admin.getByLabel(/^Message/).fill(`QA-RC please sign ${title}`);
        await send.click();
        await expect(admin.getByText('Signature requested.').first()).toBeVisible();
        await expect(card(admin, title)).toContainText('Waiting');
        // the form resets after a send; pick the signer again
        await signer.click();
        await signer.fill('QA Employee');
        await admin.getByRole('option', { name: /QA Employee/ }).first().click();
    }
    await admin.context().close();
});

test('employee: Sign needs a typed name and password; a wrong password is refused; the right one signs and shows a verification code', async ({ browser }) => {
    const emp = await personaPage(browser, PERSONAS.employee);
    await emp.goto('/app/hrms/signatures');
    const row = card(emp, docs.sign);
    await expect(row).toContainText('Waiting', { timeout: 30000 });
    await row.getByRole('button', { name: 'Sign', exact: true }).click();
    const dlg = emp.getByRole('dialog').filter({ hasText: 'Full name' }).last();
    const now = dlg.getByRole('button', { name: 'Sign document' });
    await expect(now).toBeDisabled();
    await dlg.getByLabel(/^Full name/).fill('QA Employee');
    await expect(now).toBeDisabled();
    await dlg.locator('input[type=password]').fill('definitely-wrong');
    await now.click();
    await expect(emp.getByText('Signed.')).toHaveCount(0);
    await expect(dlg.getByRole('alert').or(dlg.locator('p.text-red-600')).first()).toBeVisible({ timeout: 15000 });
    await dlg.locator('input[type=password]').fill('password');
    await now.click();
    await expect(card(emp, docs.sign)).toContainText('Signed', { timeout: 20000 });
    await expect(card(emp, docs.sign).locator('a.font-mono')).toBeVisible();
    await emp.reload();
    await expect(card(emp, docs.sign)).toContainText('Signed', { timeout: 30000 });
    await emp.context().close();
});

test('the public verification page confirms the signature code without signing in', async ({ browser }) => {
    const emp = await personaPage(browser, PERSONAS.employee);
    await emp.goto('/app/hrms/signatures');
    const href = await card(emp, docs.sign).locator('a.font-mono').getAttribute('href', { timeout: 30000 });
    await emp.context().close();
    const anon = await (await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' })).newPage();
    await anon.goto(href);
    await expect(anon.getByText(/Receipt is genuine/).first()).toBeVisible({ timeout: 30000 });
    await expect(anon.getByText('QA Employee').first()).toBeVisible();
    await anon.context().close();
});

test('decline needs a reason (in-app dialog) and is recorded with that reason', async ({ browser }) => {
    const emp = await personaPage(browser, PERSONAS.employee);
    await emp.goto('/app/hrms/signatures');
    await card(emp, docs.decline).getByRole('button', { name: 'Decline' }).click({ timeout: 30000 });
    const dlg = emp.getByRole('dialog').filter({ hasText: 'Why are you declining?' });
    await expect(dlg.getByRole('button', { name: 'Decline' })).toBeDisabled();
    await dlg.getByRole('textbox').fill('QA-RC terms unacceptable');
    await dlg.getByRole('button', { name: 'Decline' }).click();
    await expect(card(emp, docs.decline)).toContainText('Declined', { timeout: 20000 });
    await expect(card(emp, docs.decline)).toContainText('QA-RC terms unacceptable');
    await emp.context().close();
});

test('admin sees the outcomes and can cancel a still-pending request', async ({ browser }) => {
    const admin = await personaPage(browser, OWNER);
    await admin.goto('/app/hrms/signatures');
    await expect(card(admin, docs.sign)).toContainText('Signed', { timeout: 30000 });
    await expect(card(admin, docs.decline)).toContainText('Declined');
    await card(admin, docs.cancel).getByRole('button', { name: 'Cancel request' }).click();
    await expect(card(admin, docs.cancel)).toContainText('Cancelled', { timeout: 20000 });
    await admin.context().close();
});

test('a cancelled request can no longer be signed, and the Employee cannot request signatures', async ({ browser }) => {
    const emp = await personaPage(browser, PERSONAS.employee);
    await emp.goto('/app/hrms/signatures');
    await expect(card(emp, docs.cancel)).toContainText('Cancelled', { timeout: 30000 });
    await expect(card(emp, docs.cancel).getByRole('button', { name: 'Sign', exact: true })).toHaveCount(0);
    await expect(emp.getByRole('button', { name: 'Send request' })).toHaveCount(0);
    const xsrf = decodeURIComponent((await emp.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const res = await emp.request.post('/api/hrms/signatures', { data: { employee_id: 1, document_id: 1 }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });
    expect([403, 422]).toContain(res.status());
    expect(JSON.stringify(await res.json())).not.toContain('"signature"');
    await emp.context().close();
});
