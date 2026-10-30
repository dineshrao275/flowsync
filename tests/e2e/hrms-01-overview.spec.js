import { test, expect } from '@playwright/test';
import { useSession } from './helpers';

test.describe('HRMS tab 1 — Overview (/hrms)', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/hrms');
        await expect(page.getByRole('heading', { name: 'Human Resources' })).toBeVisible();
    });

    test('headline module count equals the number of tiles shown', async ({ page }) => {
        const tiles = await page.locator('main h2 + div > *').count();
        const text = await page.getByText(/modules? enabled on your plan/i).innerText();
        expect(Number(text.match(/\d+/)[0])).toBe(tiles);
    });

    test('setup checklist reports progress and its Open links navigate', async ({ page }) => {
        await expect(page.getByText(/\d of 5 steps done/)).toBeVisible();
        const open = page.getByRole('link', { name: 'Open' }).first();
        const href = await open.getAttribute('href');
        await open.click();
        await expect(page).toHaveURL(new RegExp(`${href}$`));
    });

    test('a live tile opens its module and Planned tiles are not links', async ({ page }) => {
        await page.getByRole('link', { name: /^Leave\b/ }).first().click();
        await expect(page).toHaveURL(/\/app\/hrms\/leave/);
        await page.goBack();
        const planned = page.locator('main').getByText('Planned').first();
        await expect(planned).toBeVisible();
        await expect(planned.locator('xpath=ancestor::a')).toHaveCount(0);
    });
});

test.describe('HRMS tab 1 — Overview as a restricted Employee', () => {
    test('loads the hub, counts only its tiles, and a typed restricted URL is refused with the missing permission', async ({ page }) => {
        const { PERSONAS } = await import('./helpers');
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        await expect(page.getByRole('heading', { name: 'Human Resources' })).toBeVisible();
        const tiles = await page.locator('main h2 + div > *').count();
        const text = await page.getByText(/modules? enabled on your plan/i).innerText();
        expect(Number(text.match(/\d+/)[0])).toBe(tiles);

        await page.goto('/app/hrms/payroll');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.payroll\.run/)).toBeVisible();
    });
});
