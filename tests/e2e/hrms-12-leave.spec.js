import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, deleteQaRecords, cancelQaLeaveAsAdmin, contextFor, session as useSession } from './helpers-time';

const ADMIN = undefined;
const SUFFIX = Date.now().toString().slice(-6);
const NAME = `QA-TM-Type-${SUFFIX}`;
const CODE = `qatm${SUFFIX}`;

async function openTab(page, name) {
    await page.getByRole('button', { name, exact: true }).first().click();
}

test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 12 — Leave admin (/hrms/leave)', () => {
    test.afterAll(async ({ browser }) => {
        await deleteQaRecords(browser, { email: 'owner@globex.test', password: 'password' }, '/hrms/leave/types', 'leave_types', '/hrms/leave/types', 'QA-TM-Type');
    });

    test('Employee is refused and told which permission is missing', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/leave');
        await expect(page.getByText('Access denied')).toBeVisible(LONG);
        await expect(page.getByText(/hrms\.leave\.manage/)).toBeVisible();
    });

    test('leave types: create validates, saves, edits and deletes (QA-TM- record only)', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Types');
        await expect(page.getByText('Annual Leave')).toBeVisible(LONG);

        await page.getByRole('button', { name: 'New type', exact: true }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'New leave type' });
        await expect(dlg).toBeVisible(LONG);

        // required fields
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(dlg).toBeVisible();
        await expect(page.getByText(NAME)).toHaveCount(0);

        // boundary: negative max balance is refused
        await dlg.getByLabel('Name', { exact: true }).fill(NAME);
        await dlg.getByLabel('Code', { exact: true }).fill(CODE);
        await dlg.getByLabel(/Max/i).fill('-3');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Leave type created.')).toHaveCount(0);

        await dlg.getByLabel(/Max/i).fill('12');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Leave type created.')).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: NAME })).toBeVisible(LONG);

        // persists across reload
        await page.reload();
        await openTab(page, 'Types');
        await expect(page.getByRole('row').filter({ hasText: NAME })).toBeVisible(LONG);

        // (names are NOT unique by design: the slug is server-allocated with an auto-suffix — see results-time.md)

        // edit
        const row = page.getByRole('row').filter({ hasText: NAME });
        await row.getByRole('button', { name: 'Edit' }).click();
        const ed = page.getByRole('dialog').filter({ hasText: 'Edit leave type' });
        await ed.getByLabel('Name', { exact: true }).fill(`${NAME}-edited`);
        await ed.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Leave type updated.')).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: `${NAME}-edited` })).toBeVisible(LONG);

        // delete
        await page.getByRole('row').filter({ hasText: `${NAME}-edited` }).getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete leave type?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByText('Leave type deleted.')).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: `${NAME}-edited` })).toHaveCount(0);
    });

    test('system leave types cannot be deleted and say why', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Types');
        const row = page.getByRole('row').filter({ hasText: 'Paternity Leave' });
        await expect(row).toBeVisible(LONG);
        await row.getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete leave type?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByText('Leave type deleted.')).toHaveCount(0);
        await expect(page.getByText(/System leave types cannot be deleted/)).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: 'Paternity Leave' })).toBeVisible();
    });

    test('leave policies: New opens the form (BUG-T3), validates, saves, edits, deletes', async ({ page }) => {
        const pname = `QA-TM-Policy-${SUFFIX}`;
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Policies');
        await expect(page.getByRole('row').filter({ hasText: 'Standard' })).toBeVisible(LONG);

        await page.getByRole('button', { name: 'New policy', exact: true }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'New leave policy' });
        await expect(dlg).toBeVisible(LONG);

        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(dlg).toBeVisible();

        await dlg.getByLabel('Name', { exact: true }).fill(pname);
        await dlg.getByLabel('Year starts').fill('13');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Leave policy created.')).toHaveCount(0);

        await dlg.getByLabel('Year starts').fill('4');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Leave policy created.')).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: pname })).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: pname }).getByRole('button', { name: 'Edit' }).click();
        const ed = page.getByRole('dialog').filter({ hasText: 'Edit leave policy' });
        await ed.getByLabel('Name', { exact: true }).fill(`${pname}-edited`);
        await ed.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: `${pname}-edited` })).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: `${pname}-edited` }).getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: /Delete.*policy/i }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByRole('row').filter({ hasText: `${pname}-edited` })).toHaveCount(0, LONG);
    });

    test('blackouts: validation (end before start), create, appears in list, delete', async ({ page }) => {
        const bname = `QA-TM-Blackout-${SUFFIX}`;
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Blackouts');
        await expect(page.getByText('Blackout windows').first()).toBeVisible(LONG);
        const form = page.locator('form').filter({ has: page.getByRole('button', { name: 'Add blackout' }) });
        const fillDate = async (label, iso) => { const f = form.getByLabel(label, { exact: true }); await f.fill(iso); await f.press('Enter'); };

        await form.getByRole('button', { name: 'Add blackout' }).click();
        await expect(page.getByRole('row').filter({ hasText: bname })).toHaveCount(0);

        await form.getByLabel('Name', { exact: true }).fill(bname);
        await fillDate('From', '2027-03-10');
        await fillDate('To', '2027-03-05');
        await form.getByRole('button', { name: 'Add blackout' }).click();
        await expect(page.getByRole('row').filter({ hasText: bname })).toHaveCount(0);

        await fillDate('To', '2027-03-12');
        await form.getByLabel('Reason (shown to employees)').fill('QA-TM freeze');
        await form.getByRole('button', { name: 'Add blackout' }).click();
        await expect(page.getByRole('row').filter({ hasText: bname })).toBeVisible(LONG);

        await page.getByRole('row').filter({ hasText: bname }).getByRole('button', { name: /Delete|Remove/ }).click();
        const confirm = page.getByRole('dialog').filter({ hasText: /Delete|Remove/ }).last();
        if (await confirm.isVisible().catch(() => false)) await confirm.getByRole('button', { name: /Delete|Remove/ }).last().click();
        await expect(page.getByRole('row').filter({ hasText: bname })).toHaveCount(0, LONG);
    });

    test('adjustments: validation, a credit and its debit both land in the ledger (net zero)', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Adjustments');
        const card = page.locator('main').getByText('Adjust a balance').locator('xpath=ancestor::*[contains(@class,"rounded")][1]');
        await page.getByLabel('Employee', { exact: true }).click();
        await page.getByRole('option').filter({ hasText: 'qa.employee@globex.test' }).first().click();
        await page.getByLabel('Leave type').selectOption({ label: 'Annual Leave' });

        // required: quantity + reason
        await page.getByRole('button', { name: 'Apply adjustment' }).click();
        await expect(page.getByText('Balance adjusted.')).toHaveCount(0);

        await page.getByLabel(/Days \(\+ credit/).fill('1');
        await page.getByRole('button', { name: 'Apply adjustment' }).click();
        await expect(page.getByText('Balance adjusted.')).toHaveCount(0); // reason missing

        const note = `QA-TM adjustment ${SUFFIX}`;
        await page.getByLabel('Reason', { exact: true }).fill(note);
        await page.getByRole('button', { name: 'Apply adjustment' }).click();
        await expect(page.getByText('Balance adjusted.').first()).toBeVisible(LONG);
        await expect(page.getByRole('row').filter({ hasText: note }).first()).toBeVisible(LONG);

        await page.getByLabel(/Days \(\+ credit/).fill('-1');
        await page.getByLabel('Reason', { exact: true }).fill(`${note} reversal`);
        await page.getByRole('button', { name: 'Apply adjustment' }).click();
        await expect(page.getByRole('row').filter({ hasText: `${note} reversal` }).first()).toBeVisible(LONG);
    });

    test('requests: over-balance refused with the shortfall; a valid ask is filed; the filer cannot decide it; the employee cannot withdraw an ask HR filed', async ({ page, browser }) => {
        test.setTimeout(180_000);
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await expect(page.getByRole('button', { name: 'File for employee' })).toBeVisible(LONG);

        const adjust = async (days, reason) => {
            await openTab(page, 'Adjustments');
            await page.getByLabel('Employee', { exact: true }).click();
            await page.getByRole('option').filter({ hasText: 'qa.employee@globex.test' }).first().click();
            await page.getByLabel('Leave type').selectOption({ label: 'Annual Leave' });
            await page.getByLabel(/Days \(\+ credit/).fill(String(days));
            await page.getByLabel('Reason', { exact: true }).fill(reason);
            await page.getByRole('button', { name: 'Apply adjustment' }).click();
            await expect(page.getByText('Balance adjusted.').first()).toBeVisible(LONG);
            await openTab(page, 'Requests');
        };
        const file = async (from, to, reason) => {
            await page.getByRole('button', { name: 'File for employee' }).click();
            const d = page.getByRole('dialog').filter({ hasText: 'Request leave' }).last();
            await d.getByLabel('Employee', { exact: true }).click();
            await page.getByRole('option').filter({ hasText: 'qa.employee@globex.test' }).first().click();
            await d.getByLabel('Leave type').selectOption({ label: 'Annual Leave' });
            const f = d.getByLabel('From', { exact: true }); await f.fill(from); await f.press('Enter');
            const t = d.getByLabel('To', { exact: true }); await t.fill(to); await t.press('Enter');
            await d.getByLabel('Reason', { exact: true }).fill(reason);
            await d.getByRole('button', { name: 'Send request' }).click();
            return d;
        };
        const stale = () => page.getByRole('row').filter({ hasText: 'Submitted' }).filter({ hasText: 'QA Employee' });

        await cancelQaLeaveAsAdmin(browser, { email: 'owner@globex.test', password: 'password' }); // stale asks from an interrupted run
        await page.reload();
        await adjust(3, `QA-TM request credit ${SUFFIX}`);
        const over = await file('2026-11-02', '2026-11-30', 'QA-TM over balance');
        await expect(page.getByText(/short by/).first()).toBeVisible(LONG);
        await over.getByRole('button', { name: 'Cancel' }).click();

        await file('2026-12-14', '2026-12-15', `QA-TM-leave filed by admin ${SUFFIX}`);
        await expect(stale().first()).toBeVisible(LONG);

        // Valid rule: only the assigned approver (the employee's manager) decides; the filer never can.
        await stale().first().getByRole('button', { name: 'Reject' }).click();
        const rd = page.getByRole('dialog').filter({ hasText: /Reject leave/ }).last();
        await rd.getByRole('button', { name: 'Reject' }).last().click(); // empty reason first
        await expect(rd).toBeVisible();
        await rd.getByLabel('Reason', { exact: true }).fill('QA-TM trying to decide my own filing');
        await rd.getByRole('button', { name: 'Reject' }).last().click();
        await expect(page.getByText('Only the assigned approver can decide this request.')).toBeVisible(LONG);
        await rd.getByRole('button', { name: 'Cancel' }).click();
        await expect(stale().first()).toBeVisible();

        // Valid rule: only the requester (the admin who filed it) can cancel it. The employee is offered
        // Withdraw anyway and is refused with the reason (improvement logged: hide the button).
        const ectx = await contextFor(browser, PERSONAS.employee);
        const ep = await ectx.newPage();
        await ep.goto('/app/hrms/leave/mine');
        await expect(ep.getByRole('button', { name: 'Withdraw' }).first()).toBeVisible(LONG);
        await ep.getByRole('button', { name: 'Withdraw' }).first().click();
        await ep.getByRole('dialog').filter({ hasText: 'Withdraw leave request?' }).getByRole('button', { name: 'Withdraw', exact: true }).click();
        await expect(ep.getByText('Only the requester can cancel this approval.')).toBeVisible(LONG);
        await ectx.close();

        expect(await cancelQaLeaveAsAdmin(browser, { email: 'owner@globex.test', password: 'password' })).toBeGreaterThan(0);
        await page.reload();
        await expect(page.getByRole('button', { name: 'File for employee' })).toBeVisible(LONG);
        await expect(stale()).toHaveCount(0, LONG);
        await adjust(-3, `QA-TM request credit ${SUFFIX} reversal`);
    });

    test('balances: picking an employee lists every type, and the picker is reachable by its placeholder only (a11y note)', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Balances');
        await expect(page.getByText('Pick an employee to see their balances.')).toBeVisible(LONG);
        await page.getByPlaceholder('Select an employee…').click();
        await page.getByRole('option').filter({ hasText: 'qa.employee@globex.test' }).first().click();
        for (const t of ['Annual Leave', 'Sick Leave', 'Unpaid Leave']) {
            await expect(page.getByRole('row').filter({ hasText: t }).first()).toBeVisible(LONG);
        }
    });

    test('exemptions: an empty filing is refused', async ({ page }) => {
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/leave');
        await openTab(page, 'Exemptions');
        await page.getByRole('button', { name: 'File exemption' }).first().click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'File a statutory exemption' });
        await expect(dlg).toBeVisible(LONG);
        await dlg.getByRole('button', { name: 'File exemption' }).click();
        await expect(page.getByText(/Exemption filed/)).toHaveCount(0);
        await expect(dlg).toBeVisible();
    });
});
