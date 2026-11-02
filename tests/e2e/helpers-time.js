import { expect } from '@playwright/test';

export const LONG = { timeout: 20_000 };

/** The attendance-family pages show skeletons for several seconds on the dev stack. */
export async function gotoHrms(page, path) {
    await page.goto(`/app${path}`);
    await expect(page.locator('main')).toBeVisible();
}

export async function apiGet(page, path) {
    return page.evaluate(async (p) => {
        const r = await fetch(`/api${p}`, { headers: { Accept: 'application/json' } });
        return { status: r.status, body: await r.json().catch(() => null) };
    }, path);
}

export function xsrf(page) {
    return page.evaluate(() => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''));
}

/** Test SETUP (not the behaviour under test): point an employee's manager through the admin session. */
export async function setManager(page, employeeEmail, managerEmail) {
    return page.evaluate(async ({ employeeEmail, managerEmail }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const h = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token };
        const list = await (await fetch('/api/hrms/employees?per_page=250', { headers: h })).json();
        const find = (email) => list.employees.find((e) => e.user?.email === email);
        const emp = find(employeeEmail); const mgr = managerEmail ? find(managerEmail) : null;
        const r = await fetch(`/api/hrms/employees/${emp.id}/manager`, { method: 'POST', headers: h, body: JSON.stringify({ manager_id: mgr ? mgr.id : null }) });
        return r.status;
    }, { employeeEmail, managerEmail });
}

import fs from 'node:fs';

export const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8000';

/** A fresh browser context signed in from a saved session (so two personas never share cookies). */
export async function contextFor(browser, persona) {
    const ctx = await browser.newContext({ baseURL: BASE });
    await ctx.addCookies(JSON.parse(fs.readFileSync(`tests/e2e/.auth/${persona.email}.json`, 'utf8')));
    return ctx;
}

export async function setManagerAs(browser, admin, employeeEmail, managerEmail) {
    const ctx = await contextFor(browser, admin);
    const page = await ctx.newPage();
    await page.goto('/app/hrms');
    await expect(page.locator('main')).toBeVisible();
    const status = await setManager(page, employeeEmail, managerEmail);
    await ctx.close();
    return status;
}

/** Employee files a correction on the first still-Absent day of the last 6 that accepts one (returns that Date or null). */
export async function fileCorrection(browser, employee, reason) {
    const ctx = await contextFor(browser, employee);
    const page = await ctx.newPage();
    let filed = null;
    for (let n = 1; n <= 6 && filed === null; n++) {
        const d = new Date(); d.setDate(d.getDate() - n);
        await page.goto('/app/hrms/attendance'); // fresh calendar on the current month
        await expect(page.getByText('Month totals')).toBeVisible(LONG);
        await expect(page.getByRole('button', { name: /^1\s/ }).first()).toBeVisible(LONG);
        await showMonthOf(page, d);
        const cell = page.getByRole('button', { name: new RegExp(`^${d.getDate()}\\s*Absent`) });
        if (!(await cell.count())) continue;
        await cell.first().click();
        await page.getByRole('button', { name: 'Request correction' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'At least one corrected time' });
        await dlg.getByLabel('Corrected clock-in').fill('09:00');
        await dlg.getByLabel('Corrected clock-out').fill('18:00');
        await dlg.getByPlaceholder('Forgot to clock in at the gate…').fill(reason);
        await dlg.getByRole('button', { name: 'Send request' }).click();
        const ok = page.getByText('Correction requested. Your manager will review it.');
        await expect(ok.or(dlg.getByRole('alert')).or(dlg.getByText(/already|pending/i)).first()).toBeVisible(LONG);
        if (await ok.count()) { filed = d; break; }
    }
    await ctx.close();
    return filed;
}

/** Test SETUP: credit/debit a leave balance through the admin session (the Adjustments form is covered in tab 12). */
export async function adjustLeave(browser, admin, employeeEmail, typeName, days, reason, year = 2026) {
    const ctx = await contextFor(browser, admin);
    const page = await ctx.newPage();
    await page.goto('/app/hrms');
    await expect(page.locator('main')).toBeVisible();
    const status = await page.evaluate(async ({ employeeEmail, typeName, days, reason, year }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const h = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token };
        const emps = await (await fetch('/api/hrms/employees?per_page=250', { headers: h })).json();
        const types = await (await fetch('/api/hrms/leave/types', { headers: h })).json();
        const emp = emps.employees.find((e) => e.user?.email === employeeEmail);
        const type = types.leave_types.find((t) => t.name === typeName);
        const r = await fetch('/api/hrms/leave/adjustments', { method: 'POST', headers: h, body: JSON.stringify({ employee_id: emp.id, leave_type_id: type.id, year, quantity: days, reason }) });
        return r.status;
    }, { employeeEmail, typeName, days, reason, year });
    await ctx.close();
    return status;
}

import { TENANT_ADMIN } from './helpers';

const AUTH_DIR_TIME = 'tests/e2e/.auth';

/**
 * Like helpers.useSession, but verifies the saved cookies still work (sessions get invalidated while
 * several suites run) and signs in again — once — only when they do not. Keeps login attempts minimal.
 */
export async function session(page, persona = TENANT_ADMIN) {
    const file = `${AUTH_DIR_TIME}/${persona.email}.json`;
    const alive = async () => (await page.request.get('/api/auth/me', { headers: { Accept: 'application/json' } })).status() === 200;
    if (fs.existsSync(file)) {
        await page.context().addCookies(JSON.parse(fs.readFileSync(file, 'utf8')));
        if (await alive()) return;
    }
    await page.context().clearCookies();
    // The login endpoint is throttled and several suites share it: back off and retry rather than fail.
    let signedIn = false;
    for (let attempt = 0; attempt < 4 && !signedIn; attempt++) {
        if (attempt) await page.waitForTimeout(65_000);
        await page.goto('/app/login');
        await page.getByLabel(/email/i).fill(persona.email);
        await page.getByLabel(/^password/i).fill(persona.password);
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        signedIn = await page.waitForURL(/\/app\/(?!login)/, { timeout: 15_000 }).then(() => true, () => false);
    }
    expect(signedIn, `could not sign in as ${persona.email} (login throttled?)`).toBe(true);
    fs.mkdirSync(AUTH_DIR_TIME, { recursive: true });
    fs.writeFileSync(`${AUTH_DIR_TIME}/${persona.email}.json`, JSON.stringify(await page.context().cookies()));
}

/**
 * Test SETUP through the real Grant-time form (the form itself is verified in tab 14).
 * A grant is unique per work date, so a refused date is retried with another one in the live window.
 */
export async function grantCompOff(browser, admin, employeeEmail, minutes, _date, note) {
    const ctx = await browser.newContext({ baseURL: BASE });
    const page = await ctx.newPage();
    await session(page, admin);
    await gotoHrms(page, '/hrms/comp-off');
    await page.getByRole('button', { name: 'Grant time' }).click();
    const dlg = page.getByRole('dialog').filter({ hasText: 'Grant comp-off' });
    await expect(dlg).toBeVisible(LONG);
    await dlg.getByLabel('Employee', { exact: true }).click();
    await page.getByRole('option').filter({ hasText: employeeEmail }).first().click();
    await dlg.getByText('Grant comp-off').first().click();
    const start = Math.floor(Date.now() / 60000) % 55;
    let granted = false;
    for (let attempt = 0; attempt < 8 && !granted; attempt++) {
        const d = new Date(); d.setDate(d.getDate() - 3 - ((start + attempt * 7) % 55));
        const work = dlg.getByLabel('Work date'); await work.fill(iso(d)); await work.press('Enter');
        await dlg.getByLabel('Minutes').fill(String(minutes));
        await dlg.getByLabel(/Note/).fill(note);
        await dlg.getByRole('button', { name: 'Grant', exact: true }).click();
        const ok = page.getByText('Comp-off granted.').first();
        granted = await ok.waitFor({ timeout: 6000 }).then(() => true, () => false);
    }
    expect(granted, 'no free work date accepted a comp-off grant').toBe(true);
    await ctx.close();
}

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** A comp-off grant is unique per work date; pick one from the last ~60 days (so its 90-day credit is still live), varying by minute. */
export function uniqueGrantDate() {
    const d = new Date();
    d.setDate(d.getDate() - 60 + (Math.floor(Date.now() / 60000) % 55));
    return iso(d);
}

/** Remove every roster row of the two QA people through the real Remove action (admin page). */
export async function clearQaRosters(browser, admin) {
    const ctx = await browser.newContext({ baseURL: BASE });
    const ap = await ctx.newPage();
    await session(ap, admin);
    await gotoHrms(ap, '/hrms/shifts');
    await expect(ap.getByText('Assign a shift')).toBeVisible(LONG);
    await ap.waitForTimeout(1200);
    for (let i = 0; i < 10; i++) {
        const row = ap.getByRole('row').filter({ hasText: /QA Employee|QA HR Executive/ }).first();
        if (!(await row.count())) break;
        await row.getByRole('button', { name: 'Remove' }).click();
        await ap.getByRole('dialog').filter({ hasText: 'Remove roster row?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(ap.getByText('Roster row removed.').first()).toBeVisible(LONG);
        await ap.waitForTimeout(1000);
    }
    await ctx.close();
}

/** Open the attendance grid on the month that contains `d` (the page opens on the current month). */
export async function showMonthOf(page, d) {
    const now = new Date();
    const back = (now.getFullYear() - d.getFullYear()) * 12 + (now.getMonth() - d.getMonth());
    for (let i = 0; i < back; i++) await page.getByRole('button', { name: /Prev/ }).click();
    const label = d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
    await expect(page.getByText(label).first()).toBeVisible(LONG);
    await expect(page.getByRole('button', { name: new RegExp(`^${d.getDate()}\\s`) }).first()).toBeVisible(LONG);
    await page.waitForTimeout(600); // let the month's days finish loading
}

/** Employee withdraws every open leave ask (clean-up so suites stay re-runnable). Returns how many were withdrawn. */
export async function withdrawAllLeave(browser, employee) {
    const ctx = await contextFor(browser, employee);
    const ep = await ctx.newPage();
    await ep.goto('/app/hrms/leave/mine');
    await expect(ep.getByText('History')).toBeVisible(LONG);
    await ep.waitForTimeout(1200);
    let n = 0;
    for (let i = 0; i < 6 && (await ep.getByRole('button', { name: 'Withdraw' }).count()); i++) {
        await ep.getByRole('button', { name: 'Withdraw' }).first().click();
        const wd = ep.getByRole('dialog').filter({ hasText: /Withdraw|Cancel/i }).last();
        if (await wd.isVisible().catch(() => false)) await wd.getByRole('button', { name: 'Withdraw', exact: true }).click();
        await ep.waitForTimeout(1500);
        n++;
    }
    await ctx.close();
    return n;
}

/** Clean-up only: the requester (admin) cancels the QA-TM leave asks they filed. No UI exists for this. */
export async function cancelQaLeaveAsAdmin(browser, admin) {
    const ctx = await contextFor(browser, admin);
    const page = await ctx.newPage();
    await page.goto('/app/hrms');
    await expect(page.locator('main')).toBeVisible();
    const n = await page.evaluate(async () => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const h = { Accept: 'application/json', 'X-XSRF-TOKEN': token };
        const body = await (await fetch('/api/hrms/leave/requests', { headers: h })).json();
        const rows = (body.requests ?? body.data ?? []).filter((r) => String(r.reason ?? '').startsWith('QA-TM') && r.status === 'submitted');
        let count = 0;
        for (const r of rows) { const res = await fetch(`/api/hrms/leave/requests/${r.id}`, { method: 'DELETE', headers: h }); if (res.ok) count++; }
        return count;
    });
    await ctx.close();
    return n;
}

/** Delete only records this suite created (name prefix), through the admin session. Never touches anything else. */
export async function deleteQaRecords(browser, admin, listPath, listKey, deletePath, prefix) {
    const ctx = await contextFor(browser, admin);
    const page = await ctx.newPage();
    await page.goto('/app/hrms');
    await expect(page.locator('main')).toBeVisible();
    const removed = await page.evaluate(async ({ listPath, listKey, deletePath, prefix }) => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const h = { Accept: 'application/json', 'X-XSRF-TOKEN': token };
        const body = await (await fetch(`/api${listPath}`, { headers: h })).json();
        const rows = (body[listKey] ?? body.data ?? []).filter((r) => String(r.name ?? '').startsWith(prefix));
        let n = 0;
        for (const r of rows) { const res = await fetch(`/api${deletePath}/${r.id}`, { method: 'DELETE', headers: h }); if (res.ok) n++; }
        return n;
    }, { listPath, listKey, deletePath, prefix });
    await ctx.close();
    return removed;
}
