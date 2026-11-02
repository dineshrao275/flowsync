import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, ensureRequiredSurvey, openEngagement, REQ, SURVEY } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const surveyRow = (page, name) => page.getByRole('listitem').filter({ has: page.getByText(name, { exact: true }) });

async function openMine(page) {
    const list = page.waitForResponse((r) => /\/hrms\/engagement\/my$/.test(r.url()), { timeout: 10_000 });
    await page.goto('/app/hrms/engagement/mine');
    await list;
    await expect(page.getByRole('heading', { name: 'My surveys' }).first()).toBeVisible({ timeout: 8000 });
}

test.describe('HRMS tab 28 — My surveys (/hrms/engagement/mine)', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000' });
        await useSession(page, TENANT_ADMIN);
        await ensureRequiredSurvey(page);
        await page.close();
    });

    test('Employee: the invited campaigns are listed with their state', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openMine(page);
        // A closed campaign leaves the list (valid: nothing to answer), which is where reruns land.
        if (await surveyRow(page, REQ.campaign).count()) {
            await expect(surveyRow(page, REQ.campaign)).toContainText(/Waiting on you|Answered/);
        } else {
            await expect(page.getByText(REQ.campaign, { exact: true })).toHaveCount(0);
        }
        await expect(surveyRow(page, SURVEY.campaign)).toContainText('Answered'); // answered during tab 28 discovery
    });

    test('required answer: skipping it and submitting is refused with a message on screen', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openMine(page);
        const row = surveyRow(page, REQ.campaign);
        test.skip((await row.count()) === 0 || (await row.getByText('Answered').count()) > 0, 'already answered (or closed) on an earlier run');

        await row.getByRole('button', { name: 'Answer' }).click();
        await expect(page.getByText(/Question 1 of 2/)).toBeVisible();
        // Move past the required question without answering it, fill the optional one, submit.
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByLabel(REQ.q2).fill('only the comment');
        await page.getByRole('button', { name: 'Submit answers' }).click();

        // Not recorded, still on the form, and the person is told what is wrong.
        await expect(page.getByText('Answer recorded — thank you.')).toHaveCount(0);
        await expect(page).toHaveURL(/\/engagement\/mine\/\d+/);
        await expect(page.getByText(REQ.q1).or(page.locator('p.text-red-600')).first()).toBeVisible();
        await expect(page.locator('p.text-red-600, [role="alert"]').first()).toBeVisible({ timeout: 5000 });
    });

    test('complete answer: recorded, the list flips to Answered, and the Answer button goes away', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openMine(page);
        const row = surveyRow(page, REQ.campaign);
        test.skip((await row.count()) === 0 || (await row.getByText('Answered').count()) > 0, 'already answered (or closed) on an earlier run');

        await row.getByRole('button', { name: 'Answer' }).click();
        await page.getByRole('button', { name: '4', exact: true }).click();
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByLabel(REQ.q2).fill('QA-PF-secret-comment');
        await page.getByRole('button', { name: 'Submit answers' }).click();
        await expect(page.getByText('Answer recorded — thank you.')).toBeVisible({ timeout: 8000 });
        await expect(page).toHaveURL(/\/engagement\/mine$/);
        await expect(surveyRow(page, REQ.campaign)).toContainText('Answered');
        await expect(surveyRow(page, REQ.campaign).getByRole('button', { name: 'Answer' })).toHaveCount(0);
    });

    test('one submission per campaign: the form says so on reopen and the API refuses a repeat', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        const mine = await (await apiCall(page, 'GET', '/hrms/engagement/my')).json();
        const list = mine.campaigns ?? mine.surveys ?? [];
        const campaign = list.find((c) => c.name === SURVEY.campaign); // open and answered: stable across reruns
        await page.goto(`/app/hrms/engagement/mine/${campaign.id}`);
        await expect(page.getByText(/Already answered/)).toBeVisible({ timeout: 8000 });

        const again = await apiCall(page, 'POST', `/hrms/engagement/campaigns/${campaign.id}/respond`, { answers: [] });
        expect([409, 422]).toContain(again.status());
    });

    test('anonymity: results are withheld below the threshold and never name the respondent', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openEngagement(page);
        const list = await (await apiCall(page, 'GET', '/hrms/engagement/campaigns')).json();
        const campaign = list.campaigns.find((c) => c.name === REQ.campaign);
        const body = await (await apiCall(page, 'GET', `/hrms/engagement/campaigns/${campaign.id}/results`)).text();
        expect(body).not.toMatch(/QA Employee|qa\.employee|QA-PF-secret-comment|only the comment/);
        expect(JSON.parse(body).results).toEqual([]); // 1 response < threshold 5
    });

    test('a login with no employment record sees an empty list, not an error', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openMine(page);
        await expect(page.getByText('Nothing waiting')).toBeVisible();
    });

    test('lifecycle: closing a campaign removes its open-state controls', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openEngagement(page);
        const row = page.getByRole('row').filter({ hasText: REQ.campaign });
        if (await row.getByRole('button', { name: 'Close' }).count()) {
            await row.getByRole('button', { name: 'Close' }).click();
            await expect(page.getByText('Campaign closed.')).toBeVisible({ timeout: 8000 });
        }
        await expect(row.getByRole('button', { name: /^Close$|^Invite$|^Open$/ })).toHaveCount(0);
        await expect(row.getByText('closed', { exact: true })).toBeVisible();
    });
});
