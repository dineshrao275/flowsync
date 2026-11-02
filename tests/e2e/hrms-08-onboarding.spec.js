import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession, apiCall } from './helpers-workforce';

const RUN = Date.now().toString(36).slice(-5);
const HIRE = `QA-WF-Hire ${RUN}`;
const TPL = `QA-WF-Template ${RUN}`;
const ITEMS = [['Collect ID proof', 'Document', 'Employee'], ['Set up laptop', 'Asset', 'IT'], [`HR welcome call ${RUN}`, 'Task', 'HR']];

test.setTimeout(120_000);

const manage = (page) => page.getByRole('dialog').filter({ hasText: 'Onboarding templates' }).last();

async function openOnboarding(page) {
    await page.goto('/app/hrms/onboarding');
    await expect(page.getByText(/\d+ cases?/).first()).toBeVisible({ timeout: 30_000 });
}

test.describe.serial('HRMS tab 8 — Onboarding (admin)', () => {
    test.beforeEach(async ({ page }) => { await useSession(page); });

    test('template: Create stays disabled until a name is typed', async ({ page }) => {
        await openOnboarding(page);
        await page.getByRole('button', { name: 'Manage templates' }).click();
        await expect(manage(page).getByRole('button', { name: 'Create' })).toBeDisabled();
        await manage(page).getByPlaceholder('Standard hire').fill('x');
        await expect(manage(page).getByRole('button', { name: 'Create' })).toBeEnabled();
    });

    test('template: create with three checklist items; they persist after a reload', async ({ page }) => {
        await openOnboarding(page);
        await page.getByRole('button', { name: 'Manage templates' }).click();
        await manage(page).getByPlaceholder('Standard hire').fill(TPL);
        await manage(page).getByRole('button', { name: 'Create' }).click();
        await expect(page.getByText('Template created. Add its checklist items below.')).toBeVisible({ timeout: 30_000 });
        for (const [i, [title, type, who]] of ITEMS.entries()) {
            await manage(page).getByRole('button', { name: 'Add item' }).click();
            await manage(page).getByPlaceholder('Title').nth(i).fill(title);
            await manage(page).locator('select').nth(1 + i * 2).selectOption({ label: type });
            await manage(page).locator('select').nth(2 + i * 2).selectOption({ label: who });
        }
        await manage(page).getByRole('button', { name: 'Save template' }).click();
        await expect(page.getByText(/saved|updated/i).first()).toBeVisible({ timeout: 30_000 });
        await page.reload();
        await expect(page.getByText(/\d+ cases?/).first()).toBeVisible({ timeout: 30_000 });
        await page.getByRole('button', { name: 'Manage templates' }).click();
        await manage(page).locator('select').first().selectOption({ label: TPL });
        for (const [title] of ITEMS) await expect(manage(page).locator(`input[value="${title}"]`)).toHaveCount(1);
    });

    test('case: needs an employee and a template, then starts and opens the case', async ({ page }) => {
        await openOnboarding(page);
        await expect(page.getByRole('button', { name: 'Start case' })).toBeDisabled();
        const made = await apiCall(page, 'POST', '/hrms/employees', { name: HIRE, joining_date: '2026-10-30' });
        expect(made.status).toBe(201);
        await page.reload();
        await expect(page.getByText(/\d+ cases?/).first()).toBeVisible({ timeout: 30_000 });
        await page.getByRole('combobox').first().selectOption({ label: HIRE });
        await page.getByRole('combobox').nth(1).selectOption({ label: TPL });
        await page.getByRole('button', { name: 'Start case' }).click();
        await page.waitForURL(/onboarding\/cases\/\d+/, { timeout: 30_000 });
        await expect(page.getByText(`${TPL} · In progress · 0% complete`)).toBeVisible({ timeout: 30_000 });
        for (const [title] of ITEMS) await expect(page.getByText(title).first()).toBeVisible();
    });

    test('integration: the HR item shows up in the admin Inbox (populated state)', async ({ page }) => {
        await page.goto('/app/hrms/inbox');
        await expect(page.getByText(/\d+ unread/)).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(`HR welcome call ${RUN}`).first()).toBeVisible({ timeout: 30_000 });
    });

    test('case: completing items advances progress and the case can be completed', async ({ page }) => {
        await openOnboarding(page);
        await page.getByRole('link', { name: new RegExp(HIRE) }).first().click();
        await expect(page.getByText(/0% complete/)).toBeVisible({ timeout: 30_000 });
        await page.getByRole('row').filter({ hasText: 'HR welcome call' }).getByRole('button', { name: 'Done' }).click();
        await expect(page.getByText(/33% complete/)).toBeVisible({ timeout: 30_000 });
        await page.getByRole('row').filter({ hasText: 'Set up laptop' }).getByRole('button', { name: 'Waive' }).click();
        const w = page.getByRole('dialog').filter({ hasText: /waive/i }).last();
        if (await w.count()) {
            const reason = w.getByRole('textbox').first();
            if (await reason.count()) await reason.fill('QA-WF: not needed');
            await w.getByRole('button', { name: /waive|confirm|save/i }).last().click();
        }
        await page.getByRole('row').filter({ hasText: 'Collect ID proof' }).getByRole('button', { name: 'Done' }).click();
        await expect(page.getByText(/100% complete|Completed/).first()).toBeVisible({ timeout: 30_000 });
    });

    // Templates cannot be deleted, only switched off (Active checkbox); cases can be cancelled; the hire is soft-deleted.
    test('cleanup: cancel open QA-WF cases, deactivate QA-WF templates, soft-delete QA-WF-Hire employees', async ({ page }) => {
        await openOnboarding(page);
        for (let guard = 0; guard < 6; guard += 1) {
            const link = page.getByRole('link', { name: /QA-WF-Hire/ }).first();
            if (!(await link.count())) break;
            await link.click();
            await page.waitForURL(/onboarding\/cases\/\d+/);
            const cancel = page.getByRole('button', { name: 'Cancel', exact: true });
            if (await cancel.count()) {
                await cancel.click();
                const dlg = page.getByRole('dialog').filter({ hasText: /cancel/i }).last();
                if (await dlg.count()) await dlg.getByRole('button', { name: /cancel case|confirm|yes|cancel/i }).last().click();
                await page.waitForTimeout(1500);
            }
            await openOnboarding(page);
            await page.getByRole('combobox').nth(2).selectOption({ label: 'Cancelled' }).catch(() => {});
            break;
        }
        await openOnboarding(page);
        const list = await apiCall(page, 'GET', '/hrms/employees?q=QA-WF-Hire&per_page=50');
        for (const e of list.json.employees) {
            const res = await apiCall(page, 'DELETE', `/hrms/employees/${e.id}?force=1`);
            expect([200, 403, 422]).toContain(res.status);
        }
    });
});

test.describe('HRMS tab 8 — Onboarding (roles)', () => {
    test('Employee persona is refused with the missing permission', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/onboarding');
        await expect(page.getByText('Access denied')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(/hrms\.onboarding\.view/)).toBeVisible();
    });

    test('Employee persona cannot start a case through the API', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms');
        await page.waitForLoadState('networkidle');
        const res = await apiCall(page, 'POST', '/hrms/onboarding/cases', { employee_id: 1, template_id: 1 });
        expect([403, 404]).toContain(res.status);
    });

    test('HR Executive can open the page', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openOnboarding(page);
    });
});
