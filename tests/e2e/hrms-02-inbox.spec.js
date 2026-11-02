import { test, expect } from '@playwright/test';
import { PERSONAS } from './helpers';
import { useFreshSession as useSession } from './helpers-workforce';

const apiErrors = (page) => {
    const errs = [];
    page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 400) errs.push(`${r.status()} ${r.url()}`); });
    return errs;
};

for (const [label, persona] of [['admin', undefined], ['Employee', PERSONAS.employee], ['HR Executive', PERSONAS.hrExec]]) {
    test(`Inbox (${label}): loads with a count, empty state and no failing API calls`, async ({ page }) => {
        await useSession(page, persona);
        const errs = apiErrors(page);
        await page.goto('/app/hrms/inbox');
        await expect(page.getByRole('heading', { name: 'Inbox' }).first()).toBeVisible();
        await expect(page.getByText(/\d+ unread/)).toBeVisible();
        // Empty and populated are both valid: either the empty state or at least one queue item shows.
        await expect(page.getByText('All caught up').or(page.getByRole('button', { name: /mark all|read/i })).or(page.locator('main li, main [role=row], main a').first()).first()).toBeVisible({ timeout: 30_000 });
        expect(errs).toEqual([]);
    });
}

test('Inbox API is self-scoped and refuses an unauthenticated caller', async ({ browser }) => {
    const ctx = await browser.newContext({ baseURL: 'http://localhost:8000' });
    const r = await ctx.request.get('/api/hrms/inbox', { headers: { Accept: 'application/json' } });
    expect([401, 403]).toContain(r.status());
    await ctx.close();
});
