import { test, expect } from '@playwright/test';
import { login } from './helpers';

test.describe('HRMS tab 1 — Overview (/hrms)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
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
        await page.getByRole('link', { name: 'Open' }).first().click();
        await expect(page).toHaveURL(/\/app\/hrms\/employees/);
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
