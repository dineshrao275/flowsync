import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, uniq, pickPerson } from './helpers-records';

// Tab 35 — /hrms/careers (career paths for all, succession for talent managers)
test.describe.configure({ mode: 'serial' });
const note = uniq('path');
const position = uniq('Position');

test.describe('Careers — talent manager (admin)', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms/careers');
        await expect(page.getByRole('tab', { name: 'Career paths' })).toBeVisible({ timeout: 30000 });
    });

    test('Add path is disabled until both designations are chosen', async ({ page }) => {
        const add = page.getByRole('button', { name: 'Add path' });
        await expect(add).toBeDisabled();
        await page.getByLabel('From').selectOption({ label: 'Team Lead' });
        await expect(add).toBeDisabled();
        await page.getByLabel('Leads to').selectOption({ label: 'Manager' });
        await expect(add).toBeEnabled();
    });

    test('a path from a role to itself is refused with a visible message', async ({ page }) => {
        await page.getByLabel('From').selectOption({ label: 'Manager' });
        await page.getByLabel('Leads to').selectOption({ label: 'Manager' });
        await page.getByRole('button', { name: 'Add path' }).click();
        await expect(page.getByRole('alert').first()).toBeVisible({ timeout: 15000 });
        await expect(page.getByText(note)).toHaveCount(0);
    });

    test('add a path, see it listed, a duplicate is refused, remove only this one', async ({ page }) => {
        await page.getByLabel('From').selectOption({ label: 'Team Lead' });
        await page.getByLabel('Leads to').selectOption({ label: 'Manager' });
        await page.getByLabel('Note').fill(note);
        await page.getByRole('button', { name: 'Add path' }).click();
        const row = page.locator('li', { hasText: note });
        await expect(row).toContainText('Team Lead');
        await expect(row).toContainText('Manager');
        await page.reload();
        await expect(page.locator('li', { hasText: note })).toBeVisible({ timeout: 30000 });

        await page.getByLabel('From').selectOption({ label: 'Team Lead' });
        await page.getByLabel('Leads to').selectOption({ label: 'Manager' });
        await page.getByRole('button', { name: 'Add path' }).click();
        await expect(page.getByRole('alert').first()).toBeVisible({ timeout: 15000 });

        await page.locator('li', { hasText: note }).getByRole('button', { name: 'Remove' }).click();
        await expect(page.locator('li', { hasText: note })).toHaveCount(0);
    });

    test('succession: position needs a title; add position, add successor, remove successor, remove position', async ({ page }) => {
        await page.getByRole('tab', { name: 'Succession' }).click();
        await expect(page.getByRole('button', { name: 'Add position' })).toBeDisabled();
        await page.getByLabel('Key position').fill(position);
        await page.getByLabel('Risk if vacant').selectOption('high');
        await page.getByRole('button', { name: 'Add position' }).click();
        const card = page.locator('div.rounded-lg', { hasText: position });
        await expect(card).toContainText('High risk');

        const person = card.locator('input[role=combobox]');
        await person.click();
        await person.fill('QA Employee');
        await page.getByRole('option', { name: /QA Employee/ }).first().click();
        await card.getByLabel('Readiness').selectOption('now');
        await card.getByRole('button', { name: 'Add successor' }).click();
        await expect(card).toContainText('QA Employee');
        await expect(card).toContainText('Ready now');

        await card.locator('li', { hasText: 'QA Employee' }).getByRole('button', { name: 'Remove' }).click();
        await expect(card).not.toContainText('QA Employee');
        await card.getByRole('button', { name: 'Remove' }).first().click();
        await expect(page.locator('div.rounded-lg', { hasText: position })).toHaveCount(0);
    });

    test('an over-long position title is not silently swallowed', async ({ page }) => {
        await page.getByRole('tab', { name: 'Succession' }).click();
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.getByLabel('Key position').fill('x'.repeat(400));
        await page.getByRole('button', { name: 'Add position' }).click();
        await page.waitForTimeout(1500);
        await expect(page.locator('div.rounded-lg', { hasText: 'xxxxxxxxxx' })).toHaveCount(0);
        // a rejected save must tell the user (alert) rather than only throw in the console
        await expect(page.getByRole('alert').first()).toBeVisible({ timeout: 5000 });
        expect(errors).toEqual([]);
    });
});

test.describe('Careers — Employee', () => {
    test('sees career paths read-only, no succession tab, no add form, and makes no failing background call', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        const failed = [];
        page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });
        await page.goto('/app/hrms/careers');
        await expect(page.getByRole('tab', { name: 'Career paths' })).toBeVisible({ timeout: 30000 });
        await expect(page.getByRole('tab', { name: 'Succession' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Add path' })).toHaveCount(0);
        await page.waitForTimeout(1500);
        expect(failed).toEqual([]); // RC-6: used to fire GET hrms/employees -> 403
        const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
        expect((await page.request.get('/api/hrms/succession', { headers: { Accept: 'application/json' } })).status()).toBe(403);
        expect((await page.request.post('/api/hrms/career-paths', { data: {}, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } })).status()).toBe(403);
    });
});
