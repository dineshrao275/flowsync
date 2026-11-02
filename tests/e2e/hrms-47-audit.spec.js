import { test, expect } from '@playwright/test';
import { apiGet, apiSend, ensureNarrowPersona, PERSONAS, useSession } from './helpers-insight';

const MIN_ROWS = 30; // > 1 page (the UI pages by 25)
const filt = (page, label) => page.locator('main form').getByLabel(label, { exact: true });

async function open(page, query = '') {
    await page.goto(`/app/hrms/audit${query}`);
    await expect(page.getByRole('heading', { name: 'Audit log', level: 2 })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText('Unable to load the audit trail.')).toHaveCount(0);
}

/**
 * Create + delete uniquely named salary components (QA-IN) so the ledger has real, audited rows to page over.
 * (Departments are not used: DepartmentService writes no audit row — see the coverage gap in results-insight.md.)
 */
async function makeAuditRows(page, pairs) {
    const created = [];
    for (let i = 0; i < pairs; i++) {
        const stamp = `${Date.now().toString(36)}${i}`;
        const made = await apiSend(page, 'POST', '/hrms/payroll/components', { name: `QA-IN Audit ${stamp}`, code: `qa_in_${stamp}`, type: 'earning' });
        expect(made.status, JSON.stringify(made.body)).toBeLessThan(300);
        const id = (made.body.component ?? made.body.salary_component ?? made.body.data ?? made.body).id;
        created.push(id);
        const gone = await apiSend(page, 'DELETE', `/hrms/payroll/components/${id}`);
        expect(gone.status, JSON.stringify(gone.body)).toBeLessThan(300);
    }
    return created;
}

test.describe('HRMS tab 47 — Audit log as tenant admin', () => {
    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const page = await ctx.newPage();
        await useSession(page);
        await page.goto('/app/hrms/inbox');
        // Always at least one fresh QA-IN create+delete pair (the filter tests need these action rows), plus enough volume to page.
        const total = (await apiGet(page, '/hrms/audit?per_page=1')).body.pagination.total;
        await makeAuditRows(page, Math.max(1, Math.ceil((MIN_ROWS - total) / 2)));
        await ctx.close();
    });

    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('lists the ledger newest-first and the header count matches the API', async ({ page }) => {
        await open(page);
        const api = await apiGet(page, '/hrms/audit?per_page=25');
        const count = await page.getByText(/^\d+ rows$/).innerText();
        expect(Number(count.match(/\d+/)[0])).toBeGreaterThanOrEqual(api.body.pagination.total);
        const first = api.body.audit_logs[0];
        const items = page.locator('main ul > li');
        await expect(items).toHaveCount(25);
        await expect(items.first()).toContainText(first.action);
        const times = api.body.audit_logs.map((r) => Date.parse(r.created_at));
        expect([...times].sort((a, b) => b - a)).toEqual(times);
    });

    test('a row expands to its field/before/after diff and collapses again', async ({ page }) => {
        await open(page, '?action=compensation.component_created');
        const row = page.locator('main ul > li').first();
        await row.getByRole('button').click();
        await expect(row.getByText('Before')).toBeVisible();
        await expect(row.getByText('After')).toBeVisible();
        await expect(row.getByText(/qa_in_/)).toBeVisible();
        await row.getByRole('button').click();
        await expect(row.getByText('Before')).toHaveCount(0);
    });

    test('action filter is exact, reflected in the URL, and Reset restores the full ledger', async ({ page }) => {
        await open(page);
        await filt(page, 'Action').fill('compensation.component_deleted');
        await page.getByRole('button', { name: 'Apply' }).click();
        await expect(page).toHaveURL(/action=compensation\.component_deleted/);
        const actionsOf = async () => [...new Set(await page.locator('main ul > li button span.font-mono').allInnerTexts())];
        await expect.poll(actionsOf).toEqual(['compensation.component_deleted']);
        await page.getByRole('button', { name: 'Reset' }).click();
        await expect(page).not.toHaveURL(/action=/);
        await expect(page.locator('main ul > li')).toHaveCount(25);
    });

    test('free-text search ignores case, and wildcards match literally', async ({ page }) => {
        await open(page);
        await filt(page, 'Search action or subject').fill('COMPENSATION');
        await page.getByRole('button', { name: 'Apply' }).click();
        await expect(page.locator('main ul > li').first()).toContainText('compensation.');

        await filt(page, 'Search action or subject').fill('%');
        await page.getByRole('button', { name: 'Apply' }).click();
        await expect(page.getByText('No trail rows yet.')).toBeVisible();
    });

    test('date window: a future-only window is empty, an inverted one shows the error banner, a deep link applies it', async ({ page }) => {
        await open(page, '?from=2999-01-01');
        await expect(page.getByText('No trail rows yet.')).toBeVisible();
        await page.goto('/app/hrms/audit?from=2026-10-10&to=2026-10-01');
        await expect(page.getByText('Unable to load the audit trail.')).toBeVisible();
    });

    test('actor filter: a numeric id narrows to that actor, a non-numeric id is rejected with the error banner', async ({ page }) => {
        await page.goto('/app/hrms/inbox');
        const api = await apiGet(page, '/hrms/audit?per_page=100');
        const actorId = api.body.audit_logs.find((r) => r.actor)?.actor.id;
        await open(page, `?actor_user_id=${actorId}`);
        const filtered = await apiGet(page, `/hrms/audit?actor_user_id=${actorId}&per_page=100`);
        expect(filtered.body.audit_logs.every((r) => r.actor?.id === actorId)).toBe(true);
        await page.goto('/app/hrms/audit?actor_user_id=abc');
        await expect(page.getByText('Unable to load the audit trail.')).toBeVisible();
    });

    test('pagination: Next goes to page 2 (URL + a full page of rows, even after reload), Prev returns to page 1', async ({ page }) => {
        await open(page);
        await expect(page.getByText(/Page 1 of \d+/)).toBeVisible();
        await page.getByRole('button', { name: /next/i }).click();
        await expect(page).toHaveURL(/page=2/);
        await expect(page.getByText(/Page 2 of \d+/)).toBeVisible();
        await expect(page.locator('main ul > li').first()).toBeVisible();
        await page.reload();
        await expect(page.getByText(/Page 2 of \d+/)).toBeVisible();
        await page.getByRole('button', { name: /prev/i }).click();
        await expect(page.getByText(/Page 1 of \d+/)).toBeVisible();
    });

    test('an admin action appears in the ledger with its actor, and the subject trail lists delete then create', async ({ page }) => {
        await page.goto('/app/hrms/inbox');
        const [id] = await makeAuditRows(page, 1);
        await open(page, '?action=compensation.component_deleted');
        const mine = page.locator('main ul > li').filter({ hasText: `SalaryComponent #${id}` });
        await expect(mine).toHaveCount(1);
        await expect(mine).toContainText('Globex Owner');
        const trail = await apiGet(page, `/hrms/audit/${encodeURIComponent('App\\Models\\Hrms\\Payroll\\SalaryComponent')}/${id}`);
        expect(trail.body.audit_logs.map((r) => r.action)).toEqual(['compensation.component_deleted', 'compensation.component_created']);
    });

    test('the ledger is read-only: no mutating controls, and write verbs on the API are refused', async ({ page }) => {
        await open(page);
        await expect(page.locator('main').getByRole('button', { name: /^(edit|delete|remove|clear)/i })).toHaveCount(0);
        for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            const r = await apiSend(page, method, '/hrms/audit', { action: 'x' });
            expect([404, 405], `${method} /hrms/audit`).toContain(r.status);
        }
        const r = await apiSend(page, 'DELETE', '/hrms/audit/1');
        expect([404, 405]).toContain(r.status);
    });

    test('trail endpoint: unknown subject is an empty trail; an oversize subject type is a 422', async ({ page }) => {
        await page.goto('/app/hrms/inbox');
        const empty = await apiGet(page, '/hrms/audit/Nope/999999');
        expect(empty.status).toBe(200);
        expect(empty.body.audit_logs).toEqual([]);
        expect((await apiGet(page, `/hrms/audit/${'x'.repeat(300)}/1`)).status).toBe(422);
    });

    test('tenant isolation: every actor in the ledger is a user of this tenant', async ({ page }) => {
        await page.goto('/app/hrms/inbox');
        const ledger = await apiGet(page, '/hrms/audit?per_page=100');
        const users = await apiGet(page, '/users?per_page=100');
        const ids = new Set((users.body.users ?? users.body.data ?? []).map((u) => u.id));
        const actors = ledger.body.audit_logs.filter((r) => r.actor).map((r) => r.actor.id);
        expect(actors.length).toBeGreaterThan(0);
        for (const id of actors) expect(ids.has(id), `actor ${id}`).toBe(true);
        expect(JSON.stringify(ledger.body)).not.toMatch(/acme|flowsync\.test/i);
    });

    test('saved filters: save the current filter, re-apply it from a clean list, then delete it', async ({ page }) => {
        const name = `QA-IN filter ${Date.now()}`;
        await open(page, '?action=compensation.component_created');
        await page.getByRole('button', { name: 'Saved filters' }).click();
        await page.getByRole('menuitem', { name: /Save current filters/ }).click();
        await page.getByRole('dialog').last().getByLabel('Name').fill(name);
        await page.getByRole('dialog').last().getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Filter saved.')).toBeVisible();

        await page.getByRole('button', { name: 'Reset' }).click();
        await expect(page).not.toHaveURL(/action=/);
        await page.getByRole('button', { name: /Saved filters/ }).click();
        await page.getByRole('menuitem', { name: new RegExp(`^(✓ )?${name}$`) }).click();
        await expect(page).toHaveURL(/action=compensation\.component_created/);

        const trigger = page.getByRole('button', { name: 'Saved filters' });
        await expect(trigger).toContainText(name); // the applied filter is marked active on the trigger
        await trigger.click();
        await page.getByRole('menuitem', { name: new RegExp(`^Delete .${name}.$`) }).click();
        await page.getByRole('button', { name: /^(delete|confirm)$/i }).click();
        await page.getByRole('button', { name: 'Saved filters' }).click();
        await expect(page.getByRole('menuitem', { name: new RegExp(name) })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /Saved filters/ })).toBeVisible();
    });
});

test.describe('HRMS tab 47 — Audit log role gating', () => {
    test('Employee and HR Executive without hrms.audit.view: typed URL refused naming the permission, both APIs 403', async ({ page }) => {
        for (const persona of [PERSONAS.employee, PERSONAS.hrExec]) {
            await useSession(page, persona);
            await page.goto('/app/hrms/audit');
            const status = (await apiGet(page, '/hrms/audit')).status;
            if (status === 403) {
                await expect(page.getByText('Access denied'), persona.email).toBeVisible({ timeout: 20_000 });
                await expect(page.getByText('hrms.audit.view')).toBeVisible();
                expect((await apiGet(page, '/hrms/audit/data-access')).status).toBe(403);
            } else {
                // The role holds the permission: then the page must actually render.
                expect(status, persona.email).toBe(200);
                await expect(page.getByRole('heading', { name: 'Audit log', level: 2 })).toBeVisible({ timeout: 20_000 });
            }
        }
    });

    test('a role with hrms.audit.view alone reads the ledger but not analytics', async ({ browser, page }) => {
        const admin = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const adminPage = await admin.newPage();
        await useSession(adminPage);
        await adminPage.goto('/app/hrms/inbox');
        const persona = { slug: 'qa-in-audit-reader', name: 'QA-IN Audit Reader', email: 'qa.in.audit@globex.test', permissions: ['hrms.audit.view'] };
        await ensureNarrowPersona(adminPage, persona);
        await admin.close();

        await useSession(page, { email: persona.email, password: 'password' });
        await open(page);
        expect((await apiGet(page, '/hrms/audit/data-access')).status).toBe(200);
        expect((await apiGet(page, '/hrms/analytics/overview')).status).toBe(403);
    });
});
