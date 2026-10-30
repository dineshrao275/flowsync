import { test, expect } from '@playwright/test';
import { useSession, PERSONAS } from './helpers';

// Setup for role/permission checks on every HRMS tab. Created through the real Add user form.
test.describe('Test personas (Users page)', () => {
    test.beforeEach(async ({ page }) => {
        await useSession(page);
        await page.goto('/app/users');
        await page.getByRole('button', { name: 'Add user' }).first().click();
        await expect(page.getByText('Accounts can be created by tenant admins.')).toBeVisible();
    });

    const form = (page) => ({
        name: page.getByPlaceholder('Jane Doe'),
        email: page.getByPlaceholder('jane@company.com'),
        pw: page.getByPlaceholder('At least 8 characters'),
        confirm: page.getByPlaceholder('Repeat password'),
        submit: page.getByRole('button', { name: 'Add user' }).last(),
    });

    test('rejects a password confirmation mismatch', async ({ page }) => {
        const f = form(page);
        await f.name.fill('QA Mismatch'); await f.email.fill('qa.mismatch@globex.test');
        await f.pw.fill('password'); await f.confirm.fill('different1');
        await page.getByRole('button', { name: /Employee/ }).first().click();
        await f.submit.click();
        await expect(page.getByText(/match/i).first()).toBeVisible();
        await page.goto('/app/users');
        await expect(page.getByText('qa.mismatch@globex.test')).toHaveCount(0);
    });

    test('rejects a duplicate email', async ({ page }) => {
        const f = form(page);
        await f.name.fill('QA Dup'); await f.email.fill('owner@globex.test');
        await f.pw.fill('password'); await f.confirm.fill('password');
        await page.getByRole('button', { name: /Employee/ }).first().click();
        await f.submit.click();
        await expect(page.getByText(/already|taken|exists/i).first()).toBeVisible();
    });

    for (const key of ['employee', 'hrExec']) {
        test(`creates the ${key} persona (idempotent)`, async ({ page }) => {
            const p = PERSONAS[key];
            await page.goto('/app/users');
            if (await page.getByText(p.email).count()) return;
            await page.getByRole('button', { name: 'Add user' }).first().click();
            const f = form(page);
            await f.name.fill(p.name); await f.email.fill(p.email);
            await f.pw.fill(p.password); await f.confirm.fill(p.password);
            await page.getByRole('button', { name: new RegExp(`\\+?\\s*${p.role}$`) }).first().click();
            await f.submit.click();
            await expect(page.getByText(p.email).first()).toBeVisible();
        });
    }
});
