import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, uniqueGrantDate, session as useSession } from './helpers-time';

const ADMIN = undefined;
const SUFFIX = Date.now().toString().slice(-6);
test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 14 — Comp-off admin (/hrms/comp-off)', () => {
    test('Employee is refused and told which permission is missing', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/comp-off');
        await expect(page.getByText('Access denied')).toBeVisible(LONG);
        await expect(page.getByText(/hrms\.comp_off\.manage/)).toBeVisible();
    });

    test('grant time: validation, then a grant that shows up in the employee bank', async ({ page }) => {
        const GRANT_DATE = uniqueGrantDate(); // at test time: spec files are all loaded at once
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/comp-off');
        await page.getByRole('button', { name: 'Grant time' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'Grant comp-off' });
        await expect(dlg).toBeVisible(LONG);

        // the employee picker opens its list on focus and covers the buttons; click the title to dismiss it
        await dlg.getByText('Grant comp-off').first().click();

        // required
        await dlg.getByRole('button', { name: 'Grant', exact: true }).click();
        await expect(page.getByText('Comp-off granted.')).toHaveCount(0);
        await expect(dlg).toBeVisible();

        await dlg.getByLabel('Employee', { exact: true }).click();
        await page.getByRole('option').filter({ hasText: 'qa.employee@globex.test' }).first().click();
        const d = dlg.getByLabel('Work date');
        await d.fill(GRANT_DATE); await d.press('Enter');

        // boundary: zero and negative minutes are refused
        for (const bad of ['0', '-30']) {
            await dlg.getByLabel('Minutes').fill(bad);
            await dlg.getByRole('button', { name: 'Grant', exact: true }).click();
            await expect(page.getByText('Comp-off granted.')).toHaveCount(0);
        }

        await dlg.getByLabel('Minutes').fill('60');
        await dlg.getByLabel(/Note/).fill(`QA-TM comp grant ${SUFFIX}`);
        await dlg.getByRole('button', { name: 'Grant', exact: true }).click();
        await expect(page.getByText('Comp-off granted.').first()).toBeVisible(LONG);

        // visible in the bank for that employee
        await page.getByPlaceholder('Select an employee…').click();
        await page.getByRole('option').filter({ hasText: 'qa.employee@globex.test' }).first().click();
        await expect(page.getByText('Free')).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: GRANT_DATE }).first()).toBeVisible(LONG);
    });

    test('run accrual asks for confirmation and Cancel changes nothing', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/comp-off');
        await page.getByRole('button', { name: 'Run accrual' }).click();
        const c = page.getByRole('dialog').filter({ hasText: 'Credit comp-off?' });
        await expect(c).toBeVisible(LONG);
        await c.getByRole('button', { name: 'Cancel' }).click();
        await expect(c).toHaveCount(0);
        await expect(page.getByText(/Accrual finished/)).toHaveCount(0);
    });
});
