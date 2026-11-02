import { test, expect } from '@playwright/test';
import { useSession, PERSONAS, RESIGNER } from './helpers-records';

// Tab 33 — /hrms/exit-interview. Needs the approved resignation created by hrms-32 (run that spec first).
test.describe.configure({ mode: 'serial' });

test('a user with no exit in progress is told so (no interview, no form)', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/exit-interview');
    await expect(page.getByText(/no exit in progress/i)).toBeVisible({ timeout: 30000 });
    await expect(page.getByRole('button', { name: /Submit exit interview/ })).toHaveCount(0);
});

test('required questions are enforced by the server and named per field', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/exit-interview');
    await page.getByRole('button', { name: 'Submit exit interview' }).click({ timeout: 30000 });
    await expect(page.getByText('This question needs an answer.')).toHaveCount(3);
    await expect(page.getByText('Exit interview saved.')).toHaveCount(0);
});

test('submit saves, shows the completed state, and the answers persist after reload', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/exit-interview');
    await page.getByLabel('What is the main reason you are leaving?').selectOption('relocation');
    await page.getByRole('radiogroup', { name: 'My manager supported my work and growth.' }).getByRole('radio', { name: '4' }).click();
    await page.getByLabel('Would you consider returning in future?').selectOption('maybe');
    await page.getByLabel('What worked well?').fill('QA-RC great team');
    await page.getByRole('button', { name: 'Submit exit interview' }).click();
    await expect(page.getByText('Exit interview saved.')).toBeVisible();
    await expect(page.getByText(/Thank you — your answers are saved/)).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('What worked well?')).toHaveValue('QA-RC great team', { timeout: 30000 });
    await expect(page.getByLabel('What is the main reason you are leaving?')).toHaveValue('relocation');
    await expect(page.getByRole('radiogroup', { name: 'My manager supported my work and growth.' }).getByRole('radio', { name: '4' })).toHaveAttribute('aria-checked', 'true');
});

test('answers can be updated until the last day', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/exit-interview');
    await page.getByLabel('What worked well?').fill('QA-RC updated answer');
    await page.getByRole('button', { name: 'Update my answers' }).click({ timeout: 30000 });
    await expect(page.getByText('Exit interview saved.')).toBeVisible();
});

test('the API refuses an out-of-range scale and an unknown choice with per-field errors', async ({ page }) => {
    await useSession(page, RESIGNER);
    await page.goto('/app/hrms/exit-interview');
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const res = await page.request.put('/api/hrms/exit-interview', {
        data: { answers: { primary_reason: 'nope', manager_support: 9, would_return: 'yes' } },
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
    });
    expect(res.status()).toBe(422);
    const body = await res.json();
    expect(Object.keys(body.errors)).toEqual(expect.arrayContaining(['answers.primary_reason', 'answers.manager_support']));
});

test('another employee cannot read the leaver\'s interview through the API', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/exit-interview');
    const res = await page.request.get('/api/hrms/exit-interview', { headers: { Accept: 'application/json' } });
    const body = await res.json();
    expect(JSON.stringify(body)).not.toContain('QA-RC updated answer');
});
