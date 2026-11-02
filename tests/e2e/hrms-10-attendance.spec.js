import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, setManagerAs, fileCorrection, showMonthOf, session as useSession } from './helpers-time';

const ADMIN = undefined; // default tenant admin session

const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d; };

test.describe('HRMS tab 10 — Attendance (/hrms/attendance)', () => {
    test('Employee sees own calendar and none of the admin controls', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.getByText(/QA Employee · /)).toBeVisible(LONG);
        await expect(page.getByText('Month totals')).toBeVisible();
        await expect(page.locator('main').getByRole('button', { name: 'Settings' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Export CSV' })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Review queue' })).toHaveCount(0);
        await expect(page.getByLabel('Employee')).toHaveCount(0);
    });

    test('admin without an employment record is told why, not given a generic failure', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.getByText('There is no employment record to read attendance for.')).toBeVisible(LONG);
        await expect(page.getByText('Unable to load attendance.')).toHaveCount(0);
    });

    test('admin sees the admin controls and can open another employee\'s calendar', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.locator('main').getByRole('button', { name: 'Settings', exact: true })).toBeVisible(LONG);
        await expect(page.getByRole('button', { name: 'Export CSV' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Review queue' })).toBeVisible();
        await page.getByLabel('Employee').selectOption({ label: 'QA Employee' });
        await expect(page.getByText(/QA Employee · /)).toBeVisible(LONG);
    });

    test('settings: boundary validation, then a saved value persists after reload', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/attendance');
        await page.locator('main').getByRole('button', { name: 'Settings', exact: true }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'Rounding step' });
        const rounding = dlg.getByLabel('Rounding step (minutes)');
        await expect(rounding).toBeVisible(LONG);
        const original = await rounding.inputValue();

        await rounding.fill('-5');
        await dlg.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByText('Attendance settings saved.')).toHaveCount(0);

        const next = original === '10' ? '20' : '10';
        await rounding.fill(next);
        await dlg.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByText('Attendance settings saved.')).toBeVisible(LONG);

        await page.reload();
        await page.locator('main').getByRole('button', { name: 'Settings', exact: true }).click();
        const again = page.getByRole('dialog').filter({ hasText: 'Rounding step' });
        await expect(again.getByLabel('Rounding step (minutes)')).toHaveValue(next, LONG);

        // restore
        await again.getByLabel('Rounding step (minutes)').fill(original);
        await again.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByText('Attendance settings saved.')).toBeVisible(LONG);
    });

    test('Employee: correction needs at least one corrected time and a reason', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.getByText('Month totals')).toBeVisible(LONG);
        const day = daysAgo(2);
        await showMonthOf(page, day);
        await page.getByRole('button', { name: new RegExp(`^${day.getDate()}\\s`) }).first().click();
        await page.getByRole('button', { name: 'Request correction' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'At least one corrected time' });
        await expect(dlg.getByText('At least one corrected time is required.')).toBeVisible();
        await dlg.getByRole('button', { name: /request|submit|send/i }).last().click();
        await expect(page.getByText('Correction requested. Your manager will review it.')).toHaveCount(0);
    });

    test('Employee: a correction older than the 7-day window shows the rule instead of failing silently (BUG-T1)', async ({ page }) => {
        const old = daysAgo(10);
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.getByText('Month totals')).toBeVisible(LONG);
        await showMonthOf(page, old);
        const cell = page.getByRole('button', { name: new RegExp(`^${old.getDate()}\\s*Absent`) }).first();
        test.skip(!(await cell.count()), 'that day is no longer Absent (already corrected)');
        await cell.click();
        await page.getByRole('button', { name: 'Request correction' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'At least one corrected time' });
        await dlg.getByLabel('Corrected clock-in').fill('09:00');
        await dlg.getByPlaceholder('Forgot to clock in at the gate…').fill('QA-TM-correction too old');
        await dlg.getByRole('button', { name: 'Send request' }).click();
        await expect(dlg.getByText('Corrections are accepted up to 7 days back.')).toBeVisible(LONG);
    });

    test('Employee without a manager: a correction is auto-approved and the calendar reflects it', async ({ page, browser }) => {
        // Valid business rule: no resolvable approver => the approval engine auto-approves (audited as
        // approval.auto_approved). Precondition is set explicitly because tab 11 gives this employee a manager.
        expect(await setManagerAs(browser, { email: 'owner@globex.test', password: 'password' }, PERSONAS.employee.email, null)).toBe(200);
        const day = await fileCorrection(browser, PERSONAS.employee, 'QA-TM-correction forgot to clock in');
        test.skip(day === null, 'no still-Absent day left in the 7-day window');
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance');
        await showMonthOf(page, day);
        await expect(page.getByRole('button', { name: new RegExp(`^${day.getDate()}\\s*Present`) })).toBeVisible(LONG);
    });

    test('Employee: clocks in and out from the widget (or is stopped by the 5-minute duplicate-press guard)', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance');
        const main = page.locator('main');
        const guard = page.getByText('This looks like the same press recorded twice.').first();
        await expect(main.getByRole('button', { name: 'Clock in', exact: true })).toBeVisible(LONG);
        await main.getByRole('button', { name: 'Clock in', exact: true }).click();
        const outBtn = main.getByRole('button', { name: /Clock out/ });
        await expect(outBtn.or(guard)).toBeVisible(LONG);
        if (await outBtn.isVisible()) {
            await outBtn.click();
            await expect(main.getByRole('button', { name: 'Clock in', exact: true }).or(guard)).toBeVisible(LONG);
        }
    });

    test('HR Executive: review queue and employee picker, but no Settings or Export', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.getByRole('link', { name: 'Review queue' })).toBeVisible(LONG);
        await expect(page.getByLabel('Employee')).toBeVisible();
        await expect(page.locator('main').getByRole('button', { name: 'Settings', exact: true })).toHaveCount(0);
        await expect(page.locator('main').getByRole('button', { name: 'Export CSV' })).toHaveCount(0);
    });
});
