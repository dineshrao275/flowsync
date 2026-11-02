import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE, HR_EXEC } from './helpers-pay';

test.describe('HRMS tab 21 — My payslips', () => {
    test('Employee sees their published payslips, newest first, with a net figure', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/payroll/mine');
        await expect(page.getByText(/\d+ payslips?/)).toBeVisible();
        const rows = page.getByRole('listitem').filter({ hasText: /net \d/ });
        expect(await rows.count()).toBeGreaterThanOrEqual(2);
        const first = await rows.first().innerText();
        const second = await rows.nth(1).innerText();
        expect(first).toMatch(/May 2026/);
        expect(second).toMatch(/April 2026/);
    });

    test('View opens the breakdown with earnings and net, and Escape closes it', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/payroll/mine');
        await page.getByRole('button', { name: 'View', exact: true }).first().click();
        const dialog = page.getByRole('dialog', { name: /May 2026/ });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText(/Gross/i)).toBeVisible();
        await expect(dialog.getByText(/Net/i).first()).toBeVisible();
        await expect(dialog.getByRole('link', { name: /download/i })).toHaveAttribute('href', /signature=/);
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });

    test('the download link is signed: valid link serves the payslip, a tampered one is refused', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/payroll/mine');
        const href = await page.getByRole('link', { name: 'Download' }).first().getAttribute('href');
        const ok = await page.request.get(href);
        expect(ok.status()).toBe(200);
        const bad = await page.request.get(href.replace(/signature=[^&]+/, 'signature=deadbeef'));
        expect([401, 403, 404]).toContain(bad.status());
    });

    test('HR Executive (no pay basis) gets the empty state, not another person’s payslips', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/payroll/mine');
        await expect(page.getByText('No payslips yet')).toBeVisible();
        await expect(page.getByText(/net \d/)).toHaveCount(0);
    });

    test('another employee cannot read a payslip by id, but a payroll admin can', async ({ page, browser }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/payroll/mine');
        const mine = await (await page.request.get('/api/hrms/payroll/my-payslips')).json();
        const id = (mine.payslips ?? mine.data ?? mine)[0].id;
        expect(id).toBeTruthy();

        const other = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const otherPage = await other.newPage();
        await as(otherPage, HR_EXEC);
        await otherPage.goto('/app/hrms');
        const denied = await otherPage.request.get(`/api/hrms/payroll/payslips/${id}`);
        expect([403, 404]).toContain(denied.status());

        const admin = await browser.newContext({ baseURL: 'http://localhost:8000' });
        const adminPage = await admin.newPage();
        await as(adminPage, ADMIN);
        await adminPage.goto('/app/hrms');
        expect((await adminPage.request.get(`/api/hrms/payroll/payslips/${id}`)).status()).toBe(200);
        await other.close();
        await admin.close();
    });
});
