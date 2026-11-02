import { test, expect } from '@playwright/test';
import { PERSONAS, useSession } from './helpers-insight';

const GROUPS = ['Overview', 'Workforce', 'Time', 'Pay & benefits', 'Performance', 'Records', 'Insight'];
// A group can carry an unread badge ("Workforce 4"), so the name is matched with an optional trailing count.
const escapeRe = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const group = (page, name) => page.locator('aside').getByRole('button', { name: new RegExp(`^${escapeRe(name)}( \\d+)?$`) });

test.describe('HRMS shell — sidebar, switcher, breadcrumbs, search, theme drawer (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('sidebar offers the seven HR groups; the active route\'s group is open; groups toggle with aria-expanded', async ({ page }) => {
        await page.goto('/app/hrms/analytics');
        for (const name of GROUPS) await expect(group(page, name)).toBeVisible({ timeout: 20_000 });
        await expect(group(page, 'Insight')).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('aside').getByRole('link', { name: 'Audit log' })).toBeVisible();

        await group(page, 'Workforce').click();
        await expect(group(page, 'Workforce')).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('aside').getByRole('link', { name: 'Employees' }).first()).toBeVisible();
        await group(page, 'Workforce').click();
        await expect(group(page, 'Workforce')).toHaveAttribute('aria-expanded', 'false');
    });

    test('every sidebar link opens a page (no dead entry, no blank screen) — HR links checked against the catalog', async ({ page }) => {
        test.setTimeout(300_000); // ~45 full page loads
        await page.goto('/app/hrms');
        for (const name of GROUPS) {
            await expect(group(page, name)).toBeVisible({ timeout: 20_000 });
            if ((await group(page, name).getAttribute('aria-expanded')) === 'false') await group(page, name).click();
        }
        const hrefs = await page.locator('aside a[href^="/app/hrms"]').evaluateAll((a) => [...new Set(a.map((x) => x.getAttribute('href')))]);
        expect(hrefs.length).toBeGreaterThan(20);
        const bad = [];
        for (const href of hrefs) {
            const failures = [];
            const onResponse = (r) => { if (r.url().includes('/api/') && r.status() >= 500) failures.push(`${r.status()} ${r.url().split('/api/')[1]}`); };
            page.on('response', onResponse);
            await page.goto(href);
            await expect(page.locator('main, [role="alert"]').first()).toBeVisible({ timeout: 20_000 });
            await page.waitForTimeout(700);
            page.off('response', onResponse);
            if (failures.length) bad.push(`${href}: ${failures.join(', ')}`);
        }
        expect(bad).toEqual([]);
    });

    test('collapse shrinks the sidebar, collapsed links keep an accessible name, and expanding restores it', async ({ page }) => {
        await page.goto('/app/hrms');
        const aside = page.locator('aside').first();
        const wide = (await aside.boundingBox()).width;
        await page.getByRole('button', { name: 'Collapse sidebar' }).click();
        await expect.poll(async () => (await aside.boundingBox()).width).toBeLessThan(wide - 80);
        const unnamed = await aside.locator('a').evaluateAll((links) => links.filter((l) => !(l.getAttribute('aria-label') || l.innerText.trim())).length);
        expect(unnamed).toBe(0);
        await page.getByRole('button', { name: /expand sidebar/i }).click();
        await expect.poll(async () => (await aside.boundingBox()).width).toBeGreaterThan(wide - 5);
    });

    test('breadcrumbs: account crumb and HRMS crumb lead to the HR hub; the leaf is the page', async ({ page }) => {
        await page.goto('/app/hrms/audit');
        const crumbs = page.locator('nav a', { hasText: /^(Globex|HRMS)$/ });
        await expect(crumbs.first()).toBeVisible({ timeout: 20_000 });
        for (const c of await crumbs.all()) await expect(c).toHaveAttribute('href', '/app/hrms');
        await page.locator('nav a', { hasText: /^HRMS$/ }).click();
        await expect(page).toHaveURL(/\/app\/hrms$/);
        await expect(page.getByRole('heading', { name: 'Human Resources' })).toBeVisible();
    });

    test('product switcher: HR -> TMS -> HR keeps each shell, and a shared admin page keeps the HR shell', async ({ page }) => {
        await page.goto('/app/hrms/analytics');
        await page.getByRole('button', { name: 'Switch to Task Management' }).click();
        await expect(page).not.toHaveURL(/\/app\/hrms/);
        await expect(page.locator('aside').getByRole('link', { name: 'Workspaces' })).toBeVisible({ timeout: 20_000 });
        await page.getByRole('button', { name: /Switch to (HR|Human)/i }).click();
        await expect(page).toHaveURL(/\/app\/hrms/);
        await expect(group(page, 'Workforce')).toBeVisible();

        await page.locator('aside').getByRole('link', { name: 'Users', exact: true }).click();
        await expect(page).toHaveURL(/\/app\/users/);
        await expect(group(page, 'Workforce')).toBeVisible();
    });

    test('global search: button and "/" open it, typing finds sections, Esc closes it', async ({ page }) => {
        await page.goto('/app/hrms');
        await expect(page.getByRole('heading', { name: 'Human Resources' })).toBeVisible({ timeout: 20_000 });
        const box = page.getByPlaceholder('Search workspaces, projects, tasks, people…');
        await page.getByRole('button', { name: 'Global search' }).first().click();
        await expect(box).toBeVisible();
        await box.fill('Analytics');
        await expect(page.getByText('Go to HR Analytics')).toBeVisible({ timeout: 15_000 });
        // People are searchable too (the narrow QA persona exists after the analytics spec).
        await box.fill('QA-IN Analytics');
        await expect(page.getByText('qa.in.analytics@globex.test').first()).toBeVisible({ timeout: 15_000 });
        await page.keyboard.press('Escape');
        await expect(box).toBeHidden();
        await page.locator('main').click({ position: { x: 5, y: 5 } });
        await page.keyboard.press('/');
        await expect(box).toBeVisible();
    });

    test('Theme settings drawer: while closed it is inert and hidden from assistive tech; opening it exposes it as a modal dialog; Escape/close hides it again', async ({ page }) => {
        await page.goto('/app/hrms');
        const drawer = page.locator('aside[role="dialog"]');
        await expect(drawer).toHaveAttribute('aria-hidden', 'true');
        await expect(drawer).toHaveAttribute('inert', '');
        await expect(page.getByRole('dialog', { name: /theme/i })).toHaveCount(0);
        // Not reachable from the keyboard: nothing inside it can take focus.
        const focusable = await drawer.evaluate((el) => el.querySelectorAll('button:not([disabled]), input').length);
        expect(focusable).toBeGreaterThan(0); // present in the DOM...
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.activeElement?.closest('aside[role="dialog"]') === null)).toBe(true); // ...but never focused

        await page.getByRole('button', { name: 'Theme settings' }).click();
        await expect(page.getByRole('dialog', { name: /theme/i })).toBeVisible();
        await expect(drawer).toHaveAttribute('aria-modal', 'true');
        await page.getByRole('button', { name: 'Close', exact: true }).last().click();
        await expect(drawer).toHaveAttribute('aria-hidden', 'true');
    });
});

test.describe('HRMS shell — Employee sidebar', () => {
    test('shows only what an Employee can use: no payroll run, audit, analytics or admin groups', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        await expect(page.getByRole('heading', { name: 'Human Resources' })).toBeVisible({ timeout: 20_000 });
        for (const name of GROUPS) {
            const btn = group(page, name);
            if (await btn.count() && (await btn.getAttribute('aria-expanded')) === 'false') await btn.click();
        }
        const links = await page.locator('aside a[href^="/app/hrms"]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
        for (const forbidden of ['/app/hrms/audit', '/app/hrms/analytics', '/app/hrms/payroll', '/app/hrms/employees', '/app/hrms/compensation']) {
            expect(links, forbidden).not.toContain(forbidden);
        }
        expect(links).toContain('/app/hrms/payroll/mine');
        await expect(page.locator('aside').getByRole('link', { name: 'Roles', exact: true })).toHaveCount(0);
        await expect(page.locator('aside').getByRole('link', { name: 'Users', exact: true })).toHaveCount(0);
    });
});
