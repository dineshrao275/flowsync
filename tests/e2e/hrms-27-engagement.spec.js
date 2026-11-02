import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, dialogWith, fillDate, flow, openEngagement, SURVEY } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const templateDialog = (page) => dialogWith(page, 'Create template');
const campaignDialog = (page) => page.getByRole('dialog').filter({ has: page.getByRole('button', { name: 'Schedule', exact: true }) });

test.describe('HRMS tab 27 — Engagement (/hrms/engagement)', () => {
    test('admin: page loads with campaigns, templates and both create actions', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openEngagement(page);
        await expect(page.getByRole('button', { name: 'New template' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Schedule a campaign' })).toBeVisible();
    });

    test('template validation: a name over 255 characters is refused with a visible error', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openEngagement(page);
            await page.getByRole('button', { name: 'New template' }).click({ timeout: 8000 });
            const dlg = templateDialog(page);
            await dlg.getByLabel(/^Name/).fill('QA-PF-' + 'x'.repeat(260), { timeout: 8000 });
            await dlg.getByLabel(/^Question 1/).fill('Anything');
            await dlg.getByRole('button', { name: 'Create template' }).click();
            await expect(dlg.getByText(/255/)).toBeVisible({ timeout: 8000 });
        });
    });

    test('template validation: an over-long question text is refused WITH a visible message', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openEngagement(page);
            await page.getByRole('button', { name: 'New template' }).click({ timeout: 8000 });
            const dlg = templateDialog(page);
            await dlg.getByLabel(/^Name/).fill('QA-PF-LongQuestion', { timeout: 8000 });
            await dlg.getByLabel(/^Question 1/).fill('q'.repeat(2100));
            await dlg.getByRole('button', { name: 'Create template' }).click();
            await expect(dlg.getByText(/2000|too long|may not be greater/i)).toBeVisible({ timeout: 6000 });
        });
    });

    test('create: a template with a scale and a text question is saved and listed after reload', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openEngagement(page);
            if (await page.getByText(SURVEY.template, { exact: true }).count()) return;
            await page.getByRole('button', { name: 'New template' }).click({ timeout: 8000 });
            const dlg = templateDialog(page);
            await dlg.getByLabel(/^Name/).fill(SURVEY.template, { timeout: 8000 });
            await dlg.getByLabel(/^Question 1/).fill('QA-PF How happy are you?');
            await dlg.getByLabel(/^Min/).first().fill('1');
            await dlg.getByLabel(/^Max/).first().fill('5');
            await dlg.getByRole('button', { name: 'Add a question' }).click();
            await dlg.getByLabel(/^Question 2/).fill('QA-PF What should we change?');
            await dlg.getByLabel(/^Type/).nth(2).selectOption('text');
            await dlg.getByLabel(/anonymous/i).check();
            await dlg.getByRole('button', { name: 'Create template' }).click();
            await expect(page.getByText(SURVEY.template, { exact: true }).first()).toBeVisible({ timeout: 8000 });
        });
        await openEngagement(page);
        const li = page.getByText(SURVEY.template, { exact: true }).first().locator('xpath=ancestor::li[1]');
        await expect(li).toContainText('2');
        await expect(li).toContainText(/anonymous/i);
    });

    test('campaign validation: end before start and a negative threshold are refused', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openEngagement(page);
            await page.getByRole('button', { name: 'Schedule a campaign' }).click({ timeout: 8000 });
            const dlg = campaignDialog(page);
            await dlg.getByLabel(/^Template/).selectOption({ label: SURVEY.template }, { timeout: 8000 });
            await dlg.getByLabel(/^Name/).fill('QA-PF-BadDates');
            await fillDate(dlg, /^Starts at/, '2026-06-01');
            await fillDate(dlg, /^Ends at/, '2026-01-01');
            await dlg.getByRole('button', { name: 'Schedule', exact: true }).click();
            await expect(dlg.getByText(/after or equal|on or after/i)).toBeVisible({ timeout: 8000 });

            await fillDate(dlg, /^Ends at/, '2026-12-31');
            await dlg.getByLabel(/^Anonymity threshold/).fill('-2');
            await dlg.getByRole('button', { name: 'Schedule', exact: true }).click();
            await expect(dlg.getByText(/at least 1|min/i)).toBeVisible({ timeout: 8000 });
        });
    });

    test('create + lifecycle: schedule, open, invite — status and buttons follow the state', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await flow(async () => {
            await openEngagement(page);
            if (await page.getByText(SURVEY.campaign, { exact: true }).count()) return;
            await page.getByRole('button', { name: 'Schedule a campaign' }).click({ timeout: 8000 });
            const dlg = campaignDialog(page);
            await dlg.getByLabel(/^Template/).selectOption({ label: SURVEY.template }, { timeout: 8000 });
            await dlg.getByLabel(/^Name/).fill(SURVEY.campaign);
            await fillDate(dlg, /^Starts at/, '2026-01-01');
            await fillDate(dlg, /^Ends at/, '2026-12-31');
            await dlg.getByRole('button', { name: 'Schedule', exact: true }).click();
            await expect(page.getByText(SURVEY.campaign, { exact: true })).toBeVisible({ timeout: 8000 });
        });

        await openEngagement(page);
        const row = page.getByRole('row').filter({ has: page.getByText(SURVEY.campaign, { exact: true }) });
        if (await row.getByRole('button', { name: 'Open', exact: true }).count()) {
            await row.getByRole('button', { name: 'Open', exact: true }).click();
            await expect(row.getByText('open', { exact: true })).toBeVisible({ timeout: 8000 });
        }
        await expect(row.getByRole('button', { name: 'Invite' })).toBeVisible();
        await expect(row.getByRole('button', { name: 'Close' })).toBeVisible();
        await expect(row.getByRole('button', { name: 'Open', exact: true })).toHaveCount(0);

        await row.getByRole('button', { name: 'Invite' }).click();
        await expect(page.getByText('Audience invited.')).toBeVisible({ timeout: 8000 });
    });

    test('anonymity: results with fewer responses than the threshold are withheld', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openEngagement(page);
        await page.getByRole('row').filter({ has: page.getByText(SURVEY.campaign, { exact: true }) }).getByRole('button', { name: 'Results' }).click();
        const dlg = dialogWith(page, 'Results');
        await expect(dlg.getByText(/fewer|threshold|below/i)).toBeVisible({ timeout: 8000 });
    });

    test('HR Executive (engagement.view only): sees the page but no manage controls; API writes are 403', async ({ page }) => {
        await useSession(page, PERSONAS.hrExec);
        await openEngagement(page);
        await expect(page.getByText(SURVEY.campaign, { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'New template' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Schedule a campaign' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^Open$|^Invite$|^Close$/ })).toHaveCount(0);

        const r = await apiCall(page, 'POST', '/hrms/engagement/templates', { name: 'QA-PF-Forbidden', questions: [{ text: 'x' }] });
        expect(r.status()).toBe(403);
    });

    test('Employee: the manager page is refused with the missing permission named', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/engagement');
        await expect(page.getByText('Access denied')).toBeVisible();
        await expect(page.getByText(/hrms\.engagement\.view/)).toBeVisible();
    });
});
