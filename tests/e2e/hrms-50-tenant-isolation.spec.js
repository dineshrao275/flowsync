import { test, expect } from '@playwright/test';
import { as, ADMIN } from './helpers-pay';

// A second tenant in a real browser: neither side may see the other's people, claims or payroll.
const EASTMERE = { email: 'nadia.bhatt@eastmere-retail-co.test', password: 'password' };

const api = (page, path) => page.evaluate(async (p) => {
    const res = await fetch(`/api${p}`, { headers: { Accept: 'application/json' } });
    return { status: res.status, body: await res.text() };
}, path);

test.describe('Tenant isolation in the browser', () => {
    test('the other tenant’s admin sees none of Globex’s QA people, claims or pay runs', async ({ page }) => {
        await as(page, EASTMERE);
        await page.goto('/app/hrms/employees');
        await expect(page.getByText(/EMP-\d+/).first()).toBeVisible({ timeout: 20_000 });

        const people = await api(page, '/hrms/employees?search=QA&per_page=50');
        expect(people.status).toBe(200);
        expect(people.body).not.toContain('qa.employee@globex.test');
        expect(people.body).not.toContain('QA Employee');

        const claims = await api(page, '/hrms/expenses/claims');
        expect(claims.body).not.toContain('QA-FU1-Step claim');
        expect(claims.body).not.toContain('QA-PY-');

        const runs = await api(page, '/hrms/payroll/runs');
        expect(runs.body).not.toContain('Globex');
        expect(JSON.parse(runs.body).runs?.some((r) => r.period_year === 2026 && r.period_month === 5 && r.net_total === '50.00')).not.toBe(true);

        await expect(page.getByText('QA Employee')).toHaveCount(0);
    });

    test('and Globex’s admin sees none of that tenant’s people', async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/employees');
        await expect(page.getByText(/EMP-\d+/).first()).toBeVisible({ timeout: 20_000 });

        const people = await api(page, '/hrms/employees?search=Nadia&per_page=50');
        expect(people.status).toBe(200);
        expect(people.body).not.toContain('eastmere-retail-co');
        expect(people.body).not.toContain('Nadia Bhatt');

        const me = await api(page, '/auth/me');
        expect(me.body).not.toContain('eastmere');
    });
});
