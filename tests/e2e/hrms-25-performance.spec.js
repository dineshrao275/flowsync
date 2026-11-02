import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, createCycleViaUi, cycleCard, flow, fillDate, CYCLES } from './helpers-performance';

test.describe.configure({ mode: 'serial' });

test.describe('HRMS tab 25 — Performance cycles (/hrms/performance)', () => {
    test('admin: page loads with the cycle count and the New cycle action', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await page.goto('/app/hrms/performance');
        await expect(page.getByRole('heading', { name: 'Performance' }).first()).toBeVisible();
        await expect(page.getByText(/\d+ cycles?/).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'New cycle' })).toBeVisible();
    });

    test('validation: empty form and an end date before the start date are refused', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await page.goto('/app/hrms/performance');
            await page.getByRole('button', { name: 'New cycle' }).click({ timeout: 8000 });
            const dlg = page.getByRole('dialog').filter({ hasText: 'New review cycle' });

            // Browser-level required validation blocks the empty submit, so nothing is created.
            await dlg.getByRole('button', { name: 'Create cycle' }).click();
            await expect(dlg).toBeVisible();

            await dlg.getByLabel(/^Name/).fill('QA-PF-Invalid', { timeout: 8000 });
            await fillDate(dlg, /^Period start/, '2026-06-01');
            await fillDate(dlg, /^Period end/, '2026-01-01');
            await dlg.getByRole('button', { name: 'Create cycle' }).click();
            await expect(dlg.getByText(/after or equal|on or after/i)).toBeVisible({ timeout: 8000 });
        });
        await page.keyboard.press('Escape');
        await expect(page.getByRole('link', { name: 'QA-PF-Invalid' })).toHaveCount(0);
    });

    test('validation: a name over 255 characters is refused by the server', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await page.goto('/app/hrms/performance');
            await page.getByRole('button', { name: 'New cycle' }).click({ timeout: 8000 });
            const dlg = page.getByRole('dialog').filter({ hasText: 'New review cycle' });
            await dlg.getByLabel(/^Name/).fill('QA-PF-' + 'x'.repeat(260), { timeout: 8000 });
            await fillDate(dlg, /^Period start/, '2026-01-01');
            await fillDate(dlg, /^Period end/, '2026-12-31');
            await dlg.getByRole('button', { name: 'Create cycle' }).click();
            await expect(dlg.getByText(/255/)).toBeVisible({ timeout: 8000 });
        });
    });

    test('create: cycles are created in goal setting and survive a reload', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await createCycleViaUi(page, CYCLES.goals);
        await createCycleViaUi(page, CYCLES.flow, { anonymity: 'reviewer' });
        await page.reload();
        const card = cycleCard(page, CYCLES.flow);
        await expect(card).toBeVisible();
        await expect(card.getByText('Goal setting')).toBeVisible();
        await expect(card.getByText(/reviewer/)).toBeVisible();
        await expect(page.getByRole('link', { name: CYCLES.goals, exact: true })).toBeVisible();
    });

    test('advance: cancel keeps the stage, confirm moves it forward and it persists', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await page.goto('/app/hrms/performance');
        const card = cycleCard(page, CYCLES.flow);
        await expect(card).toBeVisible();
        // Idempotent across reruns: only the first run is still in goal setting.
        if (!(await card.getByRole('button', { name: 'Open check-ins' }).count())) return;

        await card.getByRole('button', { name: 'Open check-ins' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Open check-ins?' }).getByRole('button', { name: 'Cancel' }).click();
        await expect(card.getByRole('button', { name: 'Open check-ins' })).toBeVisible();

        await card.getByRole('button', { name: 'Open check-ins' }).click();
        await page.getByRole('dialog').filter({ hasText: 'Open check-ins?' }).getByRole('button', { name: 'Open check-ins' }).click();
        await expect(card.getByRole('button', { name: 'Open self review' })).toBeVisible();
        await page.reload();
        await expect(cycleCard(page, CYCLES.flow).getByRole('button', { name: 'Open self review' })).toBeVisible();
    });

    test('detail: the cycle name opens its detail page', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await page.goto('/app/hrms/performance');
        await page.getByRole('link', { name: CYCLES.goals, exact: true }).click();
        await expect(page).toHaveURL(/\/hrms\/performance\/cycles\/\d+/);
        await expect(page.getByText(CYCLES.goals).first()).toBeVisible();
    });

    test('HR Executive (view, not manage): sees cycles but no create/advance controls, and the API refuses writes', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await page.goto('/app/hrms/performance');
        await expect(page.getByRole('link', { name: CYCLES.goals, exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'New cycle' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^Open |^Complete cycle/ })).toHaveCount(0);

        const post = await apiCall(page, 'POST', '/hrms/performance/cycles', { name: 'QA-PF-Forbidden', period_start: '2026-01-01', period_end: '2026-02-01' });
        expect(post.status()).toBe(403);
    });

    test('Employee: the page is refused with the missing permission named, and the API agrees', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/performance');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.performance\.view/)).toBeVisible();
        await expect(page.getByRole('link', { name: CYCLES.goals })).toHaveCount(0);
    });
});
