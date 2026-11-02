import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, session as useSession } from './helpers-time';

const ADMIN = undefined;
const SUFFIX = Date.now().toString().slice(-6);
test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 16 — Holidays (/hrms/holidays)', () => {
    test('Employee: no sidebar entry, and a typed URL is refused naming the permission (BUG-T4)', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/attendance');
        await expect(page.getByRole('link', { name: 'Attendance', exact: true }).first()).toBeVisible(LONG);
        await expect(page.locator('aside, nav').getByRole('link', { name: 'Holidays', exact: true })).toHaveCount(0);
        await gotoHrms(page, '/hrms/holidays');
        await expect(page.getByText('Access denied')).toBeVisible(LONG);
        await expect(page.getByText(/hrms\.holidays\.view/)).toBeVisible();
    });

    test('HR Executive (has holidays.view) sees the calendars', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await gotoHrms(page, '/hrms/holidays');
        await expect(page.getByText('Calendars').first()).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: 'US National' }).first()).toBeVisible(LONG);
    });

    test('calendar CRUD: required, country boundary, create, edit, delete (QA-TM- record)', async ({ page }) => {
        const name = `QA-TM-Cal-${SUFFIX}`;
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/holidays');
        await expect(page.getByRole('button', { name: 'New calendar' })).toBeVisible(LONG);
        await page.getByRole('button', { name: 'New calendar' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'New calendar' }).last();
        await expect(dlg).toBeVisible(LONG);

        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(dlg).toBeVisible();
        await expect(page.getByText('Calendar created.')).toHaveCount(0);

        await dlg.getByLabel('Name', { exact: true }).fill(name);
        await dlg.getByLabel(/Country/).fill('INDIA');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Calendar created.')).toHaveCount(0);

        await dlg.getByLabel(/Country/).fill('IN');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Calendar created.').first()).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: name }).first()).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: name }).first().getByRole('button', { name: 'Edit' }).click();
        const ed = page.getByRole('dialog').filter({ hasText: 'Edit calendar' }).last();
        await ed.getByLabel('Name', { exact: true }).fill(`${name}-edited`);
        await ed.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: `${name}-edited` }).first()).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: `${name}-edited` }).first().getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete calendar?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByText('Calendar deleted.').first()).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: `${name}-edited` })).toHaveCount(0);
    });

    test('holiday CRUD inside a QA calendar: required, create, shows in the list, delete', async ({ page }) => {
        const cal = `QA-TM-HolCal-${SUFFIX}`;
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/holidays');
        await page.getByRole('button', { name: 'New calendar' }).click();
        const cd = page.getByRole('dialog').filter({ hasText: 'New calendar' }).last();
        await cd.getByLabel('Name', { exact: true }).fill(cal);
        await cd.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: cal }).first()).toBeVisible(LONG);
        await page.getByRole('row').filter({ hasText: cal }).first().getByRole('button', { name: cal }).click();
        await expect(page.getByText(/Holidays \(0\)|No holidays on this calendar yet/).first()).toBeVisible(LONG);

        await page.getByRole('button', { name: 'Add holiday', exact: true }).click();
        const hd = page.getByRole('dialog').filter({ hasText: 'New holiday' }).last();
        await hd.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(hd).toBeVisible();
        await hd.getByLabel('Name', { exact: true }).fill('QA-TM Founders Day');
        const d = hd.getByLabel('Date', { exact: true }); await d.fill('2026-12-01'); await d.press('Enter');
        await hd.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Holiday created.').first()).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: 'QA-TM Founders Day' }).first()).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: 'QA-TM Founders Day' }).first().getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete holiday?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByText('Holiday deleted.').first()).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: cal }).first().getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete calendar?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByRole('row').filter({ hasText: cal })).toHaveCount(0, LONG);
    });
});
