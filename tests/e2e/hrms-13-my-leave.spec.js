import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, adjustLeave, cancelQaLeaveAsAdmin, session as useSession } from './helpers-time';

const ADMIN = { email: 'owner@globex.test', password: 'password' };
const SUFFIX = Date.now().toString().slice(-6);
test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 13 — My leave (/hrms/leave/mine)', () => {
    test.afterAll(async ({ browser }) => {
        await cancelQaLeaveAsAdmin(browser, ADMIN);
    });

    test('shows own balances and history, and no admin controls', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/leave/mine');
        await expect(page.getByText('Balances').first()).toBeVisible(LONG);
        for (const t of ['Annual Leave', 'Sick Leave']) await expect(page.getByRole('row').filter({ hasText: t }).first()).toBeVisible();
        await expect(page.getByText('History')).toBeVisible();
        await expect(page.locator('main').getByRole('button', { name: 'File for employee' })).toHaveCount(0);
    });

    test('request validation, over-balance, overlap, then the employee withdraws their own ask', async ({ page, browser }) => {
        test.setTimeout(180_000);
        expect(await adjustLeave(browser, ADMIN, PERSONAS.employee.email, 'Annual Leave', 3, `QA-TM my-leave credit ${SUFFIX}`)).toBe(201);
        try {
            await useSession(page, PERSONAS.employee);
            await gotoHrms(page, '/hrms/leave/mine');
            await expect(page.getByRole('button', { name: 'Request leave' })).toBeVisible(LONG);

            const open = async () => {
                await page.getByRole('button', { name: 'Request leave' }).click();
                return page.getByRole('dialog').filter({ hasText: 'Request leave' }).last();
            };
            const dates = async (d, from, to) => {
                const f = d.getByLabel('From', { exact: true }); await f.fill(from); await f.press('Enter');
                const t = d.getByLabel('To', { exact: true }); await t.fill(to); await t.press('Enter');
            };

            // required fields
            let d = await open();
            await d.getByRole('button', { name: 'Send request' }).click();
            await expect(d).toBeVisible();
            await expect(page.getByText(/Leave requested/)).toHaveCount(0);

            // end before start
            await d.getByLabel('Leave type').selectOption({ label: 'Annual Leave' });
            await dates(d, '2026-12-16', '2026-12-14');
            await d.getByLabel('Reason', { exact: true }).fill('QA-TM-myleave bad range');
            await d.getByRole('button', { name: 'Send request' }).click();
            await expect(page.getByText(/Leave requested/)).toHaveCount(0);

            // over balance
            await dates(d, '2026-11-02', '2026-11-30');
            await d.getByRole('button', { name: 'Send request' }).click();
            await expect(page.getByText(/short by/).first()).toBeVisible(LONG);

            // valid
            await dates(d, '2026-12-14', '2026-12-15');
            await d.getByLabel('Reason', { exact: true }).fill(`QA-TM-myleave own ask ${SUFFIX}`);
            await d.getByRole('button', { name: 'Send request' }).click();
            await expect(page.getByText('Leave requested. Your manager will review it.').first()).toBeVisible(LONG);
            const row = page.getByRole('row').filter({ hasText: '2026-12-14' }).filter({ hasText: 'Submitted' }).first();
            await expect(row).toBeVisible(LONG);

            // overlapping ask refused
            d = await open();
            await d.getByLabel('Leave type').selectOption({ label: 'Annual Leave' });
            await dates(d, '2026-12-15', '2026-12-15');
            await d.getByLabel('Reason', { exact: true }).fill('QA-TM-myleave overlap');
            await d.getByRole('button', { name: 'Send request' }).click();
            await expect(page.getByText('An ask already covers part of this range.')).toBeVisible(LONG);
            await d.getByRole('button', { name: 'Cancel' }).click();

            // the requester CAN withdraw their own ask
            await row.getByRole('button', { name: 'Withdraw' }).click();
            await page.getByRole('dialog').filter({ hasText: 'Withdraw leave request?' }).getByRole('button', { name: 'Withdraw', exact: true }).click();
            await expect(page.getByRole('row').filter({ hasText: '2026-12-14' }).filter({ hasText: 'Submitted' })).toHaveCount(0, LONG);
        } finally {
            await adjustLeave(browser, ADMIN, PERSONAS.employee.email, 'Annual Leave', -3, `QA-TM my-leave credit ${SUFFIX} reversal`);
        }
    });

    test('a blackout window blocks the ask and shows the reason', async ({ page, browser }) => {
        test.setTimeout(150_000);
        // setup through the admin Leave page (blackout CRUD itself is covered in tab 12)
        const actx = await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        const ap = await actx.newPage();
        await useSession(ap, ADMIN);
        await gotoHrms(ap, '/hrms/leave');
        await ap.getByRole('button', { name: 'Blackouts', exact: true }).first().click();
        const form = ap.locator('form').filter({ has: ap.getByRole('button', { name: 'Add blackout' }) });
        const bname = `QA-TM-Blackout-${SUFFIX}`;
        await form.getByLabel('Name', { exact: true }).fill(bname);
        const f = form.getByLabel('From', { exact: true }); await f.fill('2026-12-21'); await f.press('Enter');
        const t = form.getByLabel('To', { exact: true }); await t.fill('2026-12-24'); await t.press('Enter');
        await form.getByLabel('Reason (shown to employees)').fill('QA-TM year-end freeze');
        await form.getByRole('button', { name: 'Add blackout' }).click();
        await expect(ap.getByRole('row').filter({ hasText: bname })).toBeVisible(LONG);

        expect(await adjustLeave(browser, ADMIN, PERSONAS.employee.email, 'Annual Leave', 3, `QA-TM blackout credit ${SUFFIX}`)).toBe(201);
        try {
            await useSession(page, PERSONAS.employee);
            await gotoHrms(page, '/hrms/leave/mine');
            await page.getByRole('button', { name: 'Request leave' }).click();
            const d = page.getByRole('dialog').filter({ hasText: 'Request leave' }).last();
            await d.getByLabel('Leave type').selectOption({ label: 'Annual Leave' });
            const ff = d.getByLabel('From', { exact: true }); await ff.fill('2026-12-22'); await ff.press('Enter');
            const tt = d.getByLabel('To', { exact: true }); await tt.fill('2026-12-23'); await tt.press('Enter');
            await d.getByLabel('Reason', { exact: true }).fill('QA-TM-myleave inside blackout');
            await d.getByRole('button', { name: 'Send request' }).click();
            await expect(page.getByText(/year-end freeze|blackout/i).first()).toBeVisible(LONG);
            await expect(page.getByText(/Leave requested/)).toHaveCount(0);
        } finally {
            await adjustLeave(browser, ADMIN, PERSONAS.employee.email, 'Annual Leave', -3, `QA-TM blackout credit ${SUFFIX} reversal`);
            await ap.getByRole('row').filter({ hasText: bname }).getByRole('button', { name: /Delete|Remove/ }).click();
            const c = ap.getByRole('dialog').filter({ hasText: /Delete|Remove/ }).last();
            if (await c.isVisible().catch(() => false)) await c.getByRole('button', { name: /Delete|Remove/ }).last().click();
            await expect(ap.getByRole('row').filter({ hasText: bname })).toHaveCount(0, LONG);
            await actx.close();
        }
    });
});
