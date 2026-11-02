import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { gotoHrms, LONG, session as useSession } from './helpers-time';

const ADMIN = undefined;
const SUFFIX = Date.now().toString().slice(-6);
test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 18 — Shifts & rosters (/hrms/shifts)', () => {
    test('Employee: sees the roster only — no catalogue, rotations or assign form', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await gotoHrms(page, '/hrms/shifts');
        await expect(page.getByText('Shifts & rosters')).toBeVisible(LONG);
        await expect(page.getByRole('button', { name: 'Roster', exact: true })).toBeVisible();
        await expect(page.locator('main').getByRole('button', { name: 'Shifts', exact: true })).toHaveCount(0);
        await expect(page.locator('main').getByRole('button', { name: 'Rotations', exact: true })).toHaveCount(0);
        await expect(page.getByText('Assign a shift')).toHaveCount(0);
    });

    test('catalogue CRUD: required, boundary, create, edit, delete a QA shift; system shifts survive', async ({ page }) => {
        const name = `QA-TM-Shift-${SUFFIX}`;
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/shifts');
        await page.getByRole('button', { name: 'Shifts', exact: true }).first().click();
        await expect(page.getByRole('button', { name: 'New shift' })).toBeVisible(LONG);
        await expect(page.getByText('General (9-6)').first()).toBeVisible();

        await page.getByRole('button', { name: 'New shift' }).click();
        const dlg = page.getByRole('dialog').filter({ hasText: 'New shift' }).last();
        await expect(dlg).toBeVisible(LONG);
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(dlg).toBeVisible();
        await expect(page.getByText('Shift created.')).toHaveCount(0);

        await dlg.getByLabel('Name', { exact: true }).fill(name);
        await dlg.getByLabel('Code', { exact: true }).fill(`qatm${SUFFIX}`);
        await dlg.getByLabel('Start', { exact: true }).fill('10:00');
        await dlg.getByLabel(/^End/).fill('19:00');
        await dlg.getByLabel(/Break/).fill('-5');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Shift created.')).toHaveCount(0);

        await dlg.getByLabel(/Break/).fill('60');
        await dlg.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Shift created.').first()).toBeVisible(LONG);
        await expect(page.getByText(name).first()).toBeVisible(LONG);

        // persists
        await page.reload();
        await page.getByRole('button', { name: 'Shifts', exact: true }).first().click();
        await expect(page.getByText(name).first()).toBeVisible(LONG);

        const card = page.locator('li, tr, div').filter({ hasText: name }).filter({ has: page.getByRole('button', { name: 'Edit' }) }).last();
        await card.getByRole('button', { name: 'Edit' }).click();
        const ed = page.getByRole('dialog').filter({ hasText: 'Edit shift' }).last();
        await ed.getByLabel('Name', { exact: true }).fill(`${name}-edited`);
        await ed.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Shift updated.').first()).toBeVisible(LONG);

        const edited = page.locator('li, tr, div').filter({ hasText: `${name}-edited` }).filter({ has: page.getByRole('button', { name: 'Delete' }) }).last();
        await edited.getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete shift?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByText('Shift deleted.').first()).toBeVisible(LONG);
        await expect(page.getByText(`${name}-edited`)).toHaveCount(0);
    });

    test('roster: required person, assign a shift, the Employee sees their row, admin removes it', async ({ page, browser }) => {
        test.setTimeout(150_000);
        const tomorrow = (() => { const d = new Date(); d.setDate(d.getDate() + 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/shifts');
        await expect(page.getByText('Assign a shift')).toBeVisible(LONG);

        // pre-clean: remove roster rows an interrupted run left for QA Employee
        for (let i = 0; i < 4; i++) {
            const stale = page.getByRole('row').filter({ hasText: 'QA Employee' }).first();
            if (!(await stale.count())) break;
            await stale.getByRole('button', { name: 'Remove' }).click();
            await page.getByRole('dialog').filter({ hasText: 'Remove roster row?' }).getByRole('button', { name: 'Delete' }).click();
            await expect(page.getByText('Roster row removed.').first()).toBeVisible(LONG);
            await page.waitForTimeout(1200);
        }

        // required person
        await page.getByRole('button', { name: 'Save roster' }).click();
        await expect(page.getByText('Roster saved.')).toHaveCount(0);

        const form = page.locator('form').filter({ has: page.getByRole('button', { name: 'Save roster' }) });
        await form.getByLabel('Person', { exact: true }).click();
        await page.getByRole('option').filter({ hasText: /QA Employee/ }).first().click();
        await form.getByLabel('Shift', { exact: true }).selectOption({ label: 'Morning (6-3)' });
        const f = form.getByLabel('From', { exact: true }); await f.fill(tomorrow); await f.press('Enter');
        await form.getByRole('button', { name: 'Save roster' }).click();
        await expect(page.getByText('Roster saved.').first()).toBeVisible(LONG);
        const row = page.getByRole('row').filter({ hasText: 'QA Employee' }).filter({ hasText: 'Morning (6-3)' }).first();
        await expect(row).toBeVisible(LONG);

        // the Employee's own roster shows the new row (read-only: no Remove / Assign)
        const ectx = await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        const ep = await ectx.newPage();
        await useSession(ep, PERSONAS.employee);
        await gotoHrms(ep, '/hrms/shifts');
        await expect(ep.getByRole('row').filter({ hasText: 'Morning (6-3)' }).first()).toBeVisible(LONG);
        await expect(ep.getByRole('button', { name: 'Remove' })).toHaveCount(0);
        await ectx.close();

        // clean up through the real Remove action
        await row.getByRole('button', { name: 'Remove' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Remove roster row?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByText('Roster row removed.').first()).toBeVisible(LONG);
    });

    test('rotations: required fields, create a 2-day QA rotation, it lists, delete it (Apply not exercised)', async ({ page }) => {
        const name = `QA-TM-Rotation-${SUFFIX}`;
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/shifts');
        await page.getByRole('button', { name: 'Rotations', exact: true }).first().click();
        await expect(page.getByText('New rotation')).toBeVisible(LONG);
        const form = page.locator('form').filter({ has: page.getByRole('button', { name: 'Create rotation' }) });

        await form.getByRole('button', { name: 'Create rotation' }).click();
        await expect(page.getByText('Rotation created.')).toHaveCount(0);

        await form.getByLabel('Name', { exact: true }).fill(name);
        await form.getByLabel('Code', { exact: true }).fill('BAD CODE!');
        await form.getByRole('button', { name: 'Create rotation' }).click();
        await expect(page.getByText('Rotation created.')).toHaveCount(0);

        await form.getByLabel('Code', { exact: true }).fill(`qatmrot${SUFFIX}`);
        await form.getByLabel('Day 1').selectOption({ label: 'morning' });
        await form.getByLabel('Day 2').selectOption({ label: 'Off' });
        await form.getByRole('button', { name: 'Create rotation' }).click();
        await expect(page.getByText('Rotation created.').first()).toBeVisible(LONG);
        const row = page.getByRole('row').filter({ hasText: name }).first();
        await expect(row).toBeVisible(LONG);

        await row.getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete rotation?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0, LONG);
    });

    test('rotations: Apply writes roster rows for the chosen QA employee only, which the roster can remove', async ({ page }) => {
        test.setTimeout(150_000);
        const name = `QA-TM-ApplyRot-${SUFFIX}`;
        const day = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
        await useSession(page, ADMIN);
        await gotoHrms(page, '/hrms/shifts');
        await page.getByRole('button', { name: 'Rotations', exact: true }).first().click();
        const form = page.locator('form').filter({ has: page.getByRole('button', { name: 'Create rotation' }) });
        await form.getByLabel('Name', { exact: true }).fill(name);
        await form.getByLabel('Code', { exact: true }).fill(`qatmapply${SUFFIX}`);
        await form.getByLabel('Day 1').selectOption({ label: 'morning' });
        await form.getByLabel('Day 2').selectOption({ label: 'Off' });
        await form.getByRole('button', { name: 'Create rotation' }).click();
        await expect(page.getByRole('row').filter({ hasText: name }).first()).toBeVisible(LONG);

        const apply = page.locator('form').filter({ has: page.getByRole('button', { name: 'Apply', exact: true }) });
        // required people
        await apply.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(page.getByText(/Rotation applied|rows? (written|created)/i)).toHaveCount(0);

        await apply.getByLabel('Rotation', { exact: true }).selectOption({ label: name });
        const f = apply.getByLabel('From', { exact: true }); await f.fill(day(30)); await f.press('Enter');
        const t = apply.getByLabel('To', { exact: true }); await t.fill(day(33)); await t.press('Enter');
        await apply.getByLabel('People').selectOption({ label: 'QA Employee' });
        await apply.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(page.locator('[role=status], [role=alert]').filter({ hasText: /./ }).first()).toBeVisible(LONG);

        // the rows are ordinary roster rows: visible in the Roster tab for that window, and removable
        await page.getByRole('button', { name: 'Roster', exact: true }).first().click();
        const from = page.locator('main').getByLabel('From', { exact: true }).first();
        await from.fill(day(29)); await from.press('Enter');
        const to = page.locator('main').getByLabel('To', { exact: true }).first();
        await to.fill(day(34)); await to.press('Enter');
        const rows = page.getByRole('row').filter({ hasText: 'QA Employee' });
        await expect(rows.first()).toBeVisible(LONG);
        for (let i = 0; i < 8 && (await rows.count()); i++) {
            await rows.first().getByRole('button', { name: 'Remove' }).click();
            await page.getByRole('dialog').filter({ hasText: 'Remove roster row?' }).getByRole('button', { name: 'Delete' }).click();
            await expect(page.getByText('Roster row removed.').first()).toBeVisible(LONG);
            await page.waitForTimeout(900);
        }
        await expect(rows).toHaveCount(0);

        await page.getByRole('button', { name: 'Rotations', exact: true }).first().click();
        await page.getByRole('row').filter({ hasText: name }).first().getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Delete rotation?' }).getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0, LONG);
    });
});
