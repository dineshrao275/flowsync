import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, grantCompOff, uniqueGrantDate, session as useSession } from './helpers-time';

const ADMIN = { email: 'owner@globex.test', password: 'password' };

const SUFFIX = Date.now().toString().slice(-6);
// A grant per work date and a redemption per day are both unique, so vary them per run (weekdays of Dec 2026 / days of Sep 2026).
const WEEKDAYS = ['01', '02', '03', '04', '07', '08', '09', '10', '11', '14', '15', '16', '18', '21', '22', '23', '24'];
const REDEEM = `2026-12-${WEEKDAYS[Number(SUFFIX) % WEEKDAYS.length]}`;
test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 15 — My comp-off (/hrms/comp-off/mine)', () => {
    test('bank reflects the admin grant and shows no admin controls', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/comp-off/mine');
        await expect(page.getByText('Banked time')).toBeVisible(LONG);
        await expect(page.getByText('Free', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: /\d{4}-\d{2}-\d{2}/ }).first()).toBeVisible(LONG);
        await expect(page.locator('main').getByRole('button', { name: /Grant time|Run accrual/ })).toHaveCount(0);
    });

    test('redeem: required fields, bad range and an over-bank ask are refused (valid redemption NOT covered: bank too small)', async ({ page }) => {
        test.setTimeout(120_000);
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/comp-off/mine');
        await expect(page.getByRole('button', { name: 'Redeem time' })).toBeVisible(LONG);
        const open = async () => {
            await page.getByRole('button', { name: 'Redeem time' }).click();
            return page.getByRole('dialog').filter({ has: page.getByPlaceholder('Long weekend…') });
        };
        const dates = async (d, from, to) => {
            const f = d.getByLabel('From', { exact: true }); await f.fill(from); await f.press('Enter');
            const t = d.getByLabel('To', { exact: true }); await t.fill(to); await t.press('Enter');
        };

        let d = await open();
        await d.getByRole('button', { name: 'Send request' }).click();
        await expect(d).toBeVisible();

        await dates(d, '2026-12-17', '2026-12-16'); // end before start
        await d.getByLabel('Reason', { exact: true }).fill('QA-TM-comp bad range');
        await d.getByRole('button', { name: 'Send request' }).click();
        await expect(d).toBeVisible();

        // a full week asks for far more than the 60 banked minutes
        await dates(d, '2026-12-14', '2026-12-18');
        await d.getByRole('button', { name: 'Send request' }).click();
        await expect(d).toBeVisible();
        await expect(page.getByText(/short|enough|insufficient|bank/i).first()).toBeVisible(LONG);
        await d.getByRole('button', { name: 'Cancel' }).click();
    });

    test('redeem: a valid ask is filed and withdrawn by the employee; a second is approved by an override and spends the bank', async ({ page, browser }) => {
        test.setTimeout(240_000);
        await grantCompOff(browser, ADMIN, PERSONAS.employee.email, 960, uniqueGrantDate(), `QA-TM comp credit for redemption ${SUFFIX}`);

        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/comp-off/mine');
        await expect(page.getByRole('button', { name: 'Redeem time' })).toBeVisible(LONG);
        const redeem = async (reason) => {
            await page.getByRole('button', { name: 'Redeem time' }).click();
            const d = page.getByRole('dialog').filter({ has: page.getByPlaceholder('Long weekend…') });
            const f = d.getByLabel('From', { exact: true }); await f.fill(REDEEM); await f.press('Enter');
            const t = d.getByLabel('To', { exact: true }); await t.fill(REDEEM); await t.press('Enter');
            await d.getByLabel('Reason', { exact: true }).fill(reason);
            await d.getByRole('button', { name: 'Send request' }).click();
            return d;
        };

        await redeem(`QA-TM-comp withdraw ${SUFFIX}`);
        const row = page.getByRole('row').filter({ hasText: REDEEM }).first();
        await expect(row).toBeVisible(LONG);
        await row.getByRole('button', { name: /Withdraw|Cancel/ }).click();
        const wd = page.getByRole('dialog').filter({ hasText: /Withdraw|Cancel/i }).last();
        if (await wd.isVisible().catch(() => false)) await wd.getByRole('button', { name: /Withdraw|Confirm/ }).last().click();
        await expect(page.getByRole('row').filter({ hasText: REDEEM }).filter({ hasText: /Submitted|Pending/ })).toHaveCount(0, LONG);

        await redeem(`QA-TM-comp approve ${SUFFIX}`);
        await expect(page.getByRole('row').filter({ hasText: REDEEM }).filter({ hasText: /Submitted|Pending/ }).first()).toBeVisible(LONG);

        const actx = await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        const ap = await actx.newPage();
        await useSession(ap, ADMIN);
        await gotoHrms(ap, '/hrms/comp-off');
        const arow = ap.getByRole('row').filter({ hasText: 'QA Employee' }).filter({ hasText: /Submitted|Pending/ }).first();
        await expect(arow).toBeVisible(LONG);
        await arow.getByRole('button', { name: 'Approve' }).click();
        const dd = ap.getByRole('dialog').filter({ hasText: /Approve/ }).last();
        await dd.getByLabel(/Note/).fill('QA-TM approved by override for the test');
        await dd.getByRole('button', { name: 'Approve' }).last().click();
        await expect(ap.getByText("Comp-off approved.").first()).toBeVisible(LONG);
        await actx.close();

        await page.reload();
        await expect(page.getByRole('row').filter({ hasText: REDEEM }).filter({ hasText: /Approved/ }).first()).toBeVisible(LONG);
    });
});
