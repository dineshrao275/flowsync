import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, dialogWith, ensureCalibrationRoom, flow, CYCLES } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const EMP = 'QA Employee';
const gridRow = (page) => page.getByRole('row').filter({ hasText: EMP });

async function openTalent(page) {
    const lists = page.waitForResponse((r) => /\/hrms\/talent\/cycles\/\d+\/reviews$/.test(r.url()), { timeout: 12_000 });
    await page.goto('/app/hrms/talent');
    await lists;
    await expect(page.getByRole('heading', { name: 'Talent & calibration' })).toBeVisible({ timeout: 8000 });
}

test.describe('HRMS tab 30 — Talent / calibration (/hrms/talent)', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        await useSession(page, TENANT_ADMIN);
        await ensureCalibrationRoom(page, EMP);
        await page.close();
    });

    test('admin: the room opens on the cycle that is in calibration and lists the filed review', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openTalent(page);
        await expect(page.getByLabel('Review cycle')).toContainText(CYCLES.flow);
        await expect(gridRow(page)).toBeVisible();
        await expect(page.getByText('Calibration grid')).toBeVisible();
    });

    test('calibration validation: only ratings 1-5 can be chosen, and the API refuses anything else', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openTalent(page);
        test.skip((await gridRow(page).getByRole('button', { name: 'Calibrate' }).count()) === 0, 'already sealed on an earlier run');

        await flow(async () => {
            await openTalent(page);
            await gridRow(page).getByRole('button', { name: 'Calibrate' }).click({ timeout: 8000 });
            const dlg = dialogWith(page, 'Calibrate review');
            const values = await dlg.getByLabel(/^Calibrated rating/).locator('option').evaluateAll((o) => o.map((x) => x.value));
            expect(values).toEqual(['1', '2', '3', '4', '5']);
        });

        const cycles = await (await apiCall(page, 'GET', '/hrms/performance/cycles')).json();
        const cycle = cycles.cycles.find((c) => c.name === CYCLES.flow);
        const reviews = await (await apiCall(page, 'GET', `/hrms/talent/cycles/${cycle.id}/reviews`)).json();
        const review = reviews.reviews.find((r) => r.employee?.name === EMP);
        for (const bad of [0, 6, 'abc']) {
            expect((await apiCall(page, 'POST', `/hrms/talent/reviews/${review.id}/calibrate`, { rating: bad })).status()).toBe(422);
        }
    });

    test('calibrate: the new rating is recorded beside the original manager rating', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openTalent(page);
        test.skip((await gridRow(page).getByRole('button', { name: 'Calibrate' }).count()) === 0, 'already sealed on an earlier run');

        await flow(async () => {
            await openTalent(page);
            await gridRow(page).getByRole('button', { name: 'Calibrate' }).click({ timeout: 8000 });
            const dlg = dialogWith(page, 'Calibrate review');
            await dlg.getByLabel(/^Calibrated rating/).selectOption('4');
            await dlg.getByRole('button', { name: 'Save calibration' }).click();
            await expect(page.getByText('Review calibrated.')).toBeVisible({ timeout: 8000 });
        });
        await openTalent(page);
        const cells = await gridRow(page).getByRole('cell').allInnerTexts();
        expect(cells.join('|')).toMatch(/3.*4/); // manager 3 kept, calibrated 4 beside it
    });

    test('finalize: sealing asks for confirmation, then the review is locked', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openTalent(page);
        const row = gridRow(page);
        if (await row.getByRole('button', { name: 'Finalize' }).count()) {
            await row.getByRole('button', { name: 'Finalize' }).click();
            const confirm = dialogWith(page, 'Finalize this review?');
            await confirm.getByRole('button', { name: 'Cancel' }).click();
            await expect(row.getByRole('button', { name: 'Finalize' })).toBeVisible(); // cancel seals nothing

            await row.getByRole('button', { name: 'Finalize' }).click();
            await dialogWith(page, 'Finalize this review?').getByRole('button', { name: 'Finalize review' }).click();
            await expect(page.getByText('Review finalized.')).toBeVisible({ timeout: 8000 });
        }
        await openTalent(page);
        await expect(gridRow(page).getByText('Sealed')).toBeVisible();
        await expect(gridRow(page).getByRole('button', { name: /^Calibrate$|^Finalize$/ })).toHaveCount(0);
    });

    test('guardrail: a sealed review cannot be recalibrated through the API either', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openTalent(page);
        const cycles = await (await apiCall(page, 'GET', '/hrms/performance/cycles')).json();
        const cycle = cycles.cycles.find((c) => c.name === CYCLES.flow);
        const reviews = await (await apiCall(page, 'GET', `/hrms/talent/cycles/${cycle.id}/reviews`)).json();
        const review = reviews.reviews.find((r) => r.employee?.name === EMP);
        const again = await apiCall(page, 'POST', `/hrms/talent/reviews/${review.id}/calibrate`, { rating: 2 });
        expect(again.status()).toBeGreaterThanOrEqual(400);
        expect(again.status()).toBeLessThan(500);
    });

    test('HR Executive (no talent.manage) and Employee: the page is refused and the API agrees', async ({ page }) => {
        for (const persona of [PERSONAS.hrExec, PERSONAS.employee]) {
            await useSession(page, persona);
            await page.goto('/app/hrms/talent');
            await expect(page.getByText('Access denied')).toBeVisible();
            await expect(page.getByText(/hrms\.talent\.manage/)).toBeVisible();
            const r = await apiCall(page, 'GET', '/hrms/talent/cycles/1/reviews');
            expect([403, 404]).toContain(r.status());
        }
    });
});
