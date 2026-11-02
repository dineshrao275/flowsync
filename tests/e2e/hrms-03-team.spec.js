import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession } from './helpers-workforce';

for (const [label, persona] of [['admin', undefined], ['Employee', PERSONAS.employee], ['HR Executive', PERSONAS.hrExec]]) {
    test(`My team (${label}): renders, shows the apply-window control and no failing API calls`, async ({ page }) => {
        await useSession(page, persona);
        const errs = [];
        page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) errs.push(`${r.status()} ${r.url()}`); });
        await page.goto('/app/hrms/team');
        await expect(page.getByRole('heading', { name: 'My team' }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Apply window' })).toBeVisible();
        expect(errs).toEqual([]);
    });
}

test('My team (Employee): states there are no direct reports', async ({ page }) => {
    await useSession(page, PERSONAS.employee);
    await page.goto('/app/hrms/team');
    await expect(page.getByText(/0 direct reports/)).toBeVisible();
    await expect(page.getByText('No direct reports')).toBeVisible();
});
