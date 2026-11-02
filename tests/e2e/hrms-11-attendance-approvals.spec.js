import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, setManagerAs, fileCorrection, contextFor, showMonthOf, session as useSession } from './helpers-time';

const ADMIN = { email: 'owner@globex.test', password: 'password' };
test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 11 — Attendance approvals (/hrms/attendance/approvals)', () => {
    test.beforeAll(async ({ browser }) => {
        // QA Employee needs a manager, otherwise the engine auto-approves and nothing is ever pending.
        expect(await setManagerAs(browser, ADMIN, PERSONAS.employee.email, PERSONAS.hrExec.email)).toBe(200);
    });

    test('Employee is refused and told which permission is missing', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance/approvals');
        await expect(page.getByText('Access denied')).toBeVisible(LONG);
        await expect(page.getByText(/hrms\.attendance\.regularize/)).toBeVisible();
    });

    test('manager sees the corrected clock times, not a relative "days ago" (BUG-T2)', async ({ page, browser }) => {
        await fileCorrection(browser, PERSONAS.employee, 'QA-TM-approvals visible times');
        await useSession(page, PERSONAS.hrExec);
        await gotoHrms(page, '/hrms/attendance/approvals');
        await expect(page.getByText(/^\d+ requests?$/)).toBeVisible(LONG);
        const row = page.getByRole('row').filter({ hasText: 'QA-TM-' }).first();
        test.skip((await row.count()) === 0, 'no pending QA request and no Absent day left in the window to file one');
        await expect(row).toBeVisible(LONG);
        await expect(row).toContainText(/\d{1,2}:\d{2}\s?(AM|PM)\s→\s\d{1,2}:\d{2}\s?(AM|PM)/i);
        await expect(row).not.toContainText('ago');
    });

    test('rejecting needs a note; a rejected ask moves to the Rejected filter', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await gotoHrms(page, '/hrms/attendance/approvals');
        await expect(page.getByText(/^\d+ requests?$/)).toBeVisible(LONG);
        const row = page.getByRole('row').filter({ hasText: 'QA-TM-' }).first();
        test.skip((await row.count()) === 0, 'nothing pending to reject');
        await expect(row).toBeVisible(LONG);
        const reason = (await row.innerText()).match(/QA-TM-[^\t\n]*/)[0];
        await row.getByRole('button', { name: 'Reject' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /note|reason/i }).last();
        await dlg.getByRole('button', { name: /reject/i }).last().click();
        await expect(dlg).toBeVisible();
        await dlg.locator('input,textarea').first().fill('QA-TM-reject because the gate log disagrees');
        await dlg.getByRole('button', { name: /reject/i }).last().click();
        await expect(page.getByRole('row').filter({ hasText: reason })).toHaveCount(0, LONG);
        await page.getByRole('combobox').first().selectOption({ label: 'Rejected' });
        await expect(page.getByRole('row').filter({ hasText: reason }).first()).toContainText('Rejected', LONG);
    });

    test('approving a correction applies it to the employee calendar', async ({ page, browser }) => {
        const day = await fileCorrection(browser, PERSONAS.employee, 'QA-TM-approve and apply');
        test.skip(day === null, 'no Absent day left in the 7-day window to file a correction on');
        await useSession(page, PERSONAS.hrExec);
        await gotoHrms(page, '/hrms/attendance/approvals');
        await expect(page.getByText(/^\d+ requests?$/)).toBeVisible(LONG);
        const row = page.getByRole('row').filter({ hasText: 'QA-TM-approve and apply' }).first();
        await expect(row).toBeVisible(LONG);
        await row.getByRole('button', { name: 'Approve' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /approve/i }).last();
        if (await dlg.isVisible()) await dlg.getByRole('button', { name: /approve/i }).last().click();
        await expect(page.getByRole('row').filter({ hasText: 'QA-TM-approve and apply' })).toHaveCount(0, LONG);

        const emp = await contextFor(browser, PERSONAS.employee);
        const ep = await emp.newPage();
        await ep.goto('/app/hrms/attendance');
        await showMonthOf(ep, day);
        await expect(ep.getByRole('button', { name: new RegExp(`^${day.getDate()}\\s*Present`) })).toBeVisible(LONG);
        await emp.close();
    });
});
