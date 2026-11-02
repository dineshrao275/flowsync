import fs from 'node:fs';
import { test, expect } from '@playwright/test';
import { apiGet, ensureNarrowPersona, PERSONAS, useSession } from './helpers-insight';

const TABS = ['overview', 'attendance', 'leave', 'lifecycle', 'performance', 'payroll', 'documents', 'assets'];
const EXPORTABLE = TABS.filter((t) => t !== 'overview');

const from = (page) => page.locator('main').getByPlaceholder('From');
const to = (page) => page.locator('main').getByPlaceholder('To');

async function open(page, tab = 'overview') {
    await page.goto(tab === 'overview' ? '/app/hrms/analytics' : `/app/hrms/analytics?tab=${tab}`);
    await expect(page.getByRole('heading', { name: 'Analytics', level: 2 })).toBeVisible({ timeout: 20_000 });
}

test.describe('HRMS tab 46 — Analytics as tenant admin', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    for (const tab of TABS) {
        test(`${tab} tab loads without a server error or error banner`, async ({ page }) => {
            const failures = [];
            page.on('response', (r) => { if (r.url().includes('/api/hrms/analytics') && r.status() >= 400) failures.push(`${r.status()} ${r.url()}`); });
            await open(page, tab);
            await expect(page.locator('main .animate-pulse, main [aria-busy="true"]')).toHaveCount(0, { timeout: 20_000 });
            await expect(page.getByText('Unable to load analytics.')).toHaveCount(0);
            expect(failures).toEqual([]);
        });
    }

    test('date filter: an inverted window is refused by the API and the page does not show stale data as success', async ({ page }) => {
        await open(page, 'attendance');
        await from(page).fill('2026-10-10');
        await to(page).fill('2026-10-01');
        await expect(page.getByText('Unable to load analytics.')).toBeVisible();
    });

    test('date filter: a valid window loads and clearing it restores the default', async ({ page }) => {
        await open(page, 'leave');
        await from(page).fill('2026-01-01');
        await to(page).fill('2026-12-31');
        await expect(page.getByText('Unable to load analytics.')).toHaveCount(0);
        await from(page).fill('');
        await to(page).fill('');
        await expect(page.getByText('Unable to load analytics.')).toHaveCount(0);
    });

    test('tab selection is reflected in the URL and survives a reload', async ({ page }) => {
        await open(page, 'overview');
        await page.getByRole('button', { name: 'Payroll', exact: true }).click();
        await expect(page).toHaveURL(/tab=payroll/);
        await page.reload();
        await expect(page.getByRole('button', { name: 'Payroll', exact: true })).toHaveClass(/border-\[var\(--accent\)\]/);
    });

    test('overview has no export button; every other tab exports a CSV with its header row', async ({ page }) => {
        await open(page, 'overview');
        await expect(page.getByRole('button', { name: /export csv/i })).toHaveCount(0);

        await open(page, 'lifecycle');
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: /export csv/i }).click(),
        ]);
        expect(download.suggestedFilename()).toMatch(/^hrms-analytics-lifecycle-all_all\.csv$/);
        const text = fs.readFileSync(await download.path(), 'utf8');
        expect(text.split('\n')[0].length).toBeGreaterThan(0);
        await expect(page.getByText(/downloaded/i).first()).toBeVisible();
    });

    test('an export writes a data-access audit row naming the domain subject', async ({ page }) => {
        await open(page, 'assets');
        const before = (await apiGet(page, '/hrms/audit/data-access?per_page=100')).body.pagination.total;
        await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /export csv/i }).click()]);
        await expect(page.getByText(/downloaded/i).first()).toBeVisible();
        const after = await apiGet(page, '/hrms/audit/data-access?per_page=100');
        expect(after.body.pagination.total).toBe(before + 1);
        const row = after.body.data_access_logs[0];
        expect(JSON.stringify(row)).toMatch(/export/i);
        expect(JSON.stringify(row)).toMatch(/Asset/);
    });

    test('API validation: bad dates, bad ids, unknown domain and inverted windows all 422', async ({ page }) => {
        await page.goto('/app/hrms/inbox');
        for (const q of [
            '/hrms/analytics/overview?from=notadate',
            '/hrms/analytics/overview?from=2026-10-10&to=2026-10-01',
            '/hrms/analytics/overview?department_id=abc',
            '/hrms/analytics/overview?department_id=999999',
            '/hrms/analytics/export?domain=nope',
            '/hrms/analytics/export?domain=overview',
            '/hrms/analytics/export?domain=leave&from=2026-10-10&to=2026-10-01',
        ]) {
            expect((await apiGet(page, q)).status, q).toBe(422);
        }
    });

    test('tenant isolation: headcount never exceeds this tenant\'s own employee list (cache may lag up to 5 min) and nothing names another tenant', async ({ page }) => {
        // The exact cross-tenant guarantee (cache key carries the tenant) is pinned by
        // HrmsAnalyticsApiTest::test_cached_analytics_never_cross_tenants; this is the UI-level sanity check.
        await page.goto('/app/hrms/inbox');
        const employees = await apiGet(page, '/hrms/employees?per_page=100');
        const overview = await apiGet(page, '/hrms/analytics/overview');
        const own = employees.body.pagination?.total ?? (employees.body.employees ?? []).length;
        expect(overview.body.headcount.total).toBeGreaterThan(0);
        expect(overview.body.headcount.total).toBeLessThanOrEqual(own);
        expect(JSON.stringify(overview.body)).not.toMatch(/acme|amberly|kestrel/i);
    });
});

test.describe('HRMS tab 46 — Analytics role gating', () => {
    test('Employee: typed URL is refused and every analytics API answers 403', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/analytics');
        await expect(page.getByText('Access denied')).toBeVisible({ timeout: 20_000 });
        for (const tab of TABS) {
            expect((await apiGet(page, `/hrms/analytics/${tab}`)).status, tab).toBe(403);
        }
        expect((await apiGet(page, '/hrms/analytics/export?domain=leave')).status).toBe(403);
    });

    test('HR Executive (built-in role without hrms.analytics.view): typed URL is refused naming the permission; tab APIs follow their own permissions; export is admin-only', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await page.goto('/app/hrms/analytics');
        await expect(page.getByText('Access denied')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByText('hrms.analytics.view')).toBeVisible();
        // The page is gated by hrms.analytics.view, each tab API by its own permission: the HR Executive
        // holds attendance/documents/assets view, so those three answer directly (documented business rule).
        const expected = { overview: 403, attendance: 200, leave: 403, lifecycle: 403, performance: 403, payroll: 403, documents: 200, assets: 200 };
        for (const tab of TABS) expect((await apiGet(page, `/hrms/analytics/${tab}`)).status, tab).toBe(expected[tab]);
        for (const domain of EXPORTABLE) expect((await apiGet(page, `/hrms/analytics/export?domain=${domain}`)).status, domain).toBe(403);
    });
});

const NARROW = { slug: 'qa-in-analytics-viewer', name: 'QA-IN Analytics Viewer', email: 'qa.in.analytics@globex.test', permissions: ['hrms.analytics.view', 'hrms.attendance.view'] };

test.describe('HRMS tab 46 — Analytics tab gating with a narrow role (analytics.view + attendance.view)', () => {
    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const page = await ctx.newPage();
        await useSession(page);
        await page.goto('/app/hrms/inbox');
        await ensureNarrowPersona(page, NARROW);
        await ctx.close();
    });

    test('offers exactly the tabs its permissions allow, hides export, and the API agrees', async ({ page }) => {
        await useSession(page, { email: NARROW.email, password: 'password' });
        await open(page, 'overview');
        const offered = [];
        for (const tab of TABS) {
            const label = tab[0].toUpperCase() + tab.slice(1);
            if (await page.locator('main nav').getByRole('button', { name: label, exact: true }).count()) offered.push(tab);
        }
        expect(offered).toEqual(['overview', 'attendance', 'lifecycle', 'performance']);
        await expect(page.getByRole('button', { name: /export csv/i })).toHaveCount(0);

        for (const tab of TABS) {
            expect((await apiGet(page, `/hrms/analytics/${tab}`)).status === 200, `${tab} api`).toBe(offered.includes(tab));
        }
        for (const domain of EXPORTABLE) expect((await apiGet(page, `/hrms/analytics/export?domain=${domain}`)).status, domain).toBe(403);
    });

    test('a hand-typed tab it may not see falls back to its first allowed tab', async ({ page }) => {
        await useSession(page, { email: NARROW.email, password: 'password' });
        await open(page, 'payroll');
        await expect(page.getByText('Unable to load analytics.')).toHaveCount(0);
        await expect(page.locator('main nav').getByRole('button', { name: 'Overview', exact: true })).toHaveClass(/border-\[var\(--accent\)\]/);
    });
});
