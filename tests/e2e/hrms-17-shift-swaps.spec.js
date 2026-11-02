import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, clearQaRosters, session as useSession } from './helpers-time';

const ADMIN = { email: 'owner@globex.test', password: 'password' };
const SUFFIX = Date.now().toString().slice(-6);

test.describe.configure({ mode: 'serial' });

function tomorrow() {
    const d = new Date(); d.setDate(d.getDate() + 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

test.describe('HRMS tab 17 — Shift swaps (/hrms/shift-swaps)', () => {
    test('Employee: page loads with the empty state', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/shift-swaps');
        await expect(page.getByText('Shift swaps').first()).toBeVisible(LONG);
        await expect(page.getByRole('button', { name: 'Request a swap' })).toBeVisible();
    });

    // DEFECT (BUG-T5, not fixed — needs a product decision): the page loads its "swap with" people from
    // GET /hrms/employees, which an Employee may not call (403), so the picker is empty and the page's
    // main self-service action is unusable. test.fail() pins the desired behaviour; remove it when fixed.
    test('Employee: can choose a colleague to swap with', async ({ page }) => {
        test.fail(true, 'BUG-T5: /hrms/employees is 403 for Employee, so the colleague picker is empty');
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/shift-swaps');
        await page.getByRole('button', { name: 'Request a swap' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /swap/i }).last();
        await dlg.locator('#swap-with').click();
        await expect(page.getByRole('option').first()).toBeVisible({ timeout: 5000 });
    });

    test('HR Executive: send is disabled until colleague and date are set; server rules are shown', async ({ page, browser }) => {
        test.setTimeout(180_000);
        await clearQaRosters(browser, ADMIN); // the same-shift rule needs both people unrostered
        await useSession(page, PERSONAS.hrExec);
        await gotoHrms(page, '/hrms/shift-swaps');
        await page.getByRole('button', { name: 'Request a swap' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: /swap/i }).last();
        const send = dlg.getByRole('button', { name: /Send/ });
        await expect(send).toBeDisabled();

        await dlg.locator('#swap-with').click();
        await page.getByRole('option').filter({ hasText: /^QE\s*QA Employee$/ }).first().click();
        await expect(send).toBeDisabled(); // still no date

        const d = dlg.getByLabel('Day', { exact: true });
        await d.fill('2020-01-02'); await d.press('Enter');
        await send.click();
        await expect(dlg.getByText('A swap has to be for today or a later day.').first()).toBeVisible(LONG);

        await d.fill(tomorrow()); await d.press('Enter');
        await send.click();
        // both QA users have no roster, so the same-shift rule fires
        await expect(dlg.getByText('You both work the same shift that day, so there is nothing to swap.').first()).toBeVisible(LONG);
    });

    // Weekdays from tomorrow on (Sat/Sun are the default weekly offs).
    const weekdays = (n) => {
        const out = []; const d = new Date();
        while (out.length < n) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() !== 0 && d.getDay() !== 6) out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`);
        }
        return out;
    };

    test('swap flow: withdraw, reject (note required) and approve (roster applies) between two rostered QA people', async ({ page, browser }) => {
        test.setTimeout(260_000);
        const [dWithdraw, dReject, dApprove] = weekdays(3);
        const base = { baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' };

        // --- setup through the real roster form (covered in tab 18): different shifts on those days
        const admin = await browser.newContext(base);
        const ap = await admin.newPage();
        await useSession(ap, ADMIN);
        await gotoHrms(ap, '/hrms/shifts');
        await expect(ap.getByText('Assign a shift')).toBeVisible(LONG);
        const removeQaRows = async () => {
            for (let i = 0; i < 8; i++) {
                const row = ap.getByRole('row').filter({ hasText: /QA Employee|QA HR Executive/ }).first();
                if (!(await row.count())) break;
                await row.getByRole('button', { name: 'Remove' }).click();
                await ap.getByRole('dialog').filter({ hasText: 'Remove roster row?' }).getByRole('button', { name: 'Delete' }).click();
                await expect(ap.getByText('Roster row removed.').first()).toBeVisible(LONG);
                await ap.waitForTimeout(1000);
            }
        };
        const assign = async (who, shift) => {
            const form = ap.locator('form').filter({ has: ap.getByRole('button', { name: 'Save roster' }) });
            if (await form.getByRole('button', { name: 'Change' }).count()) await form.getByRole('button', { name: 'Change' }).click();
            await form.getByLabel('Person', { exact: true }).click();
            await ap.getByRole('option').filter({ hasText: who }).first().click();
            await form.getByLabel('Shift', { exact: true }).selectOption({ label: shift });
            const f = form.getByLabel('From', { exact: true }); await f.fill(dWithdraw); await f.press('Enter');
            await form.getByRole('button', { name: 'Save roster' }).click();
            await expect(ap.getByText('Roster saved.').first()).toBeVisible(LONG);
            await ap.waitForTimeout(1000);
        };
        try {
            await removeQaRows();
            await assign('QA Employee', 'Morning (6-3)');
            await assign('QA HR Executive', 'Night (22-7)');

            // --- requester = HR Executive
            const requesterCtx = await browser.newContext(base);
            const rp = await requesterCtx.newPage();
            await useSession(rp, PERSONAS.hrExec);
            const request = async (day, reason) => {
                await gotoHrms(rp, '/hrms/shift-swaps');
                await rp.getByRole('button', { name: 'Request a swap' }).click();
                const dlg = rp.getByRole('dialog').filter({ hasText: /swap/i }).last();
                await dlg.locator('#swap-with').click();
                await rp.getByRole('option').filter({ hasText: /^QE\s*QA Employee$/ }).first().click();
                const d = dlg.getByLabel('Day', { exact: true }); await d.fill(day); await d.press('Enter');
                await dlg.getByLabel('Reason', { exact: true }).fill(reason);
                await dlg.getByRole('button', { name: /Send/ }).click();
                await expect(rp.getByText('Swap requested.').first()).toBeVisible(LONG);
            };

            // pre-clean: withdraw swaps an interrupted earlier run left waiting (one waiting swap per day per person)
            await gotoHrms(rp, '/hrms/shift-swaps');
            await expect(rp.getByRole('button', { name: 'Request a swap' })).toBeVisible(LONG);
            await rp.waitForTimeout(1500);
            for (let i = 0; i < 6 && (await rp.getByRole('button', { name: 'Withdraw' }).count()); i++) {
                await rp.getByRole('button', { name: 'Withdraw' }).first().click();
                await rp.waitForTimeout(1500);
            }

            // withdraw
            await request(dWithdraw, `QA-TM swap to withdraw ${SUFFIX}`);
            const mine = rp.locator('li, div').filter({ hasText: `swap to withdraw ${SUFFIX}` }).filter({ has: rp.getByRole('button', { name: 'Withdraw' }) }).last();
            await expect(mine).toBeVisible(LONG);
            await mine.getByRole('button', { name: 'Withdraw' }).click();
            await expect(rp.getByText('Withdrawn').first()).toBeVisible(LONG);

            // reject by the counterparty (note needed)
            await request(dReject, `QA-TM swap to reject ${SUFFIX}`);
            const counterCtx = await browser.newContext(base);
            const cp = await counterCtx.newPage();
            await useSession(cp, PERSONAS.employee);
            await gotoHrms(cp, '/hrms/shift-swaps');
            const toDecide = () => cp.locator('li, div').filter({ hasText: `swap to reject ${SUFFIX}` }).filter({ has: cp.getByRole('button', { name: 'Reject' }) }).last();
            await expect(toDecide()).toBeVisible(LONG);
            await toDecide().getByRole('button', { name: 'Reject' }).click(); // no note
            // the friendly service message is shadowed by the generic request validation (improvement logged)
            await expect(cp.getByText('The note field is required.').first()).toBeVisible(LONG);
            await toDecide().getByLabel(/Note/).fill('QA-TM cannot cover that night');
            await toDecide().getByRole('button', { name: 'Reject' }).click();
            await expect(cp.getByText('Rejected').first()).toBeVisible(LONG);

            // approve by the counterparty -> applied
            await request(dApprove, `QA-TM swap to approve ${SUFFIX}`);
            await gotoHrms(cp, '/hrms/shift-swaps');
            const approveRow = cp.locator('li, div').filter({ hasText: `swap to approve ${SUFFIX}` }).filter({ has: cp.getByRole('button', { name: 'Approve' }) }).last();
            await expect(approveRow).toBeVisible(LONG);
            await approveRow.getByRole('button', { name: 'Approve' }).click();
            await expect(cp.getByText('Done').first()).toBeVisible(LONG);
            await requesterCtx.close();
            await counterCtx.close();
        } finally {
            await gotoHrms(ap, '/hrms/shifts');
            await removeQaRows();
            await admin.close();
        }
    });
});
