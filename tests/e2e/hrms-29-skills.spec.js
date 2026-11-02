import { test, expect } from '@playwright/test';
import { PERSONAS, TENANT_ADMIN } from './helpers';
import { useSession, apiCall, flow } from './helpers-performance';

// One retry: the shared dev server hot-reloads whenever any agent edits a file, which can interrupt a step.
test.describe.configure({ mode: 'serial', retries: 1 });

const KEEP = 'QA-PF-Skill-Keep'; // held by the Employee, so it can never be deleted (valid rule)
const TEMP = 'QA-PF-Skill-Temp'; // created, archived, restored and deleted again within the spec

async function openSkills(page, tab) {
    const list = page.waitForResponse((r) => /\/hrms\/skills$/.test(r.url()) && r.request().method() === 'GET', { timeout: 10_000 });
    await page.goto('/app/hrms/skills');
    await list;
    await expect(page.getByRole('heading', { name: 'Skills' }).first()).toBeVisible({ timeout: 8000 });
    if (tab) await page.getByRole('tab', { name: tab }).click();
}
const skillRow = (page, name) => page.getByRole('listitem').filter({ hasText: name });

test.describe('HRMS tab 29 — Skills (/hrms/skills)', () => {
    test('Employee: sees My skills, Gaps and Team matrix, but not the manager tabs', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openSkills(page);
        for (const name of ['My skills', 'Gaps', 'Team matrix']) await expect(page.getByRole('tab', { name })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'Catalogue' })).toHaveCount(0);
        await expect(page.getByRole('tab', { name: 'Role expectations' })).toHaveCount(0);
    });

    test('catalogue validation: blank name disables Add; the name field caps at 120 characters', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openSkills(page, 'Catalogue');
        await expect(page.getByRole('button', { name: 'Add skill' })).toBeDisabled();
        const name = page.getByLabel(/^Skill name/);
        await name.fill('QA-PF-' + 'x'.repeat(200));
        expect((await name.inputValue()).length).toBe(120);
        await name.fill('   ');
        await expect(page.getByRole('button', { name: 'Add skill' })).toBeDisabled();
    });

    test('catalogue create: skills are added, listed, persist and reject a duplicate name', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        for (const name of [KEEP, TEMP]) {
            await flow(async () => {
                await openSkills(page, 'Catalogue');
                if (await skillRow(page, name).count()) return;
                await page.getByLabel(/^Skill name/).fill(name, { timeout: 8000 });
                await page.getByLabel(/^Category/).fill('QA-PF');
                await page.getByRole('button', { name: 'Add skill' }).click();
                await expect(skillRow(page, name)).toBeVisible({ timeout: 8000 });
            });
        }
        await openSkills(page, 'Catalogue');
        await expect(skillRow(page, KEEP)).toContainText('QA-PF');
        await expect(skillRow(page, KEEP)).toContainText(/\d+ (person|people)/);

        await page.getByLabel(/^Skill name/).fill(KEEP);
        await page.getByRole('button', { name: 'Add skill' }).click();
        await expect(page.getByText(/already|taken|exists|unique/i).first()).toBeVisible({ timeout: 8000 });
        await expect(skillRow(page, KEEP)).toHaveCount(1);
    });

    test('catalogue lifecycle: archive shows the badge, restore clears it, delete removes the skill', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        // Re-entrant: every step reads the current state first, so a dev-server reload mid-way
        // (several agents edit the tree) just resumes where the data actually is.
        await flow(async () => {
            await openSkills(page, 'Catalogue');
            const row = skillRow(page, TEMP);
            if ((await row.count()) === 0) return;

            // Each click is followed by the page's own reload of the catalogue; wait for that
            // response so the next step never races a half-updated list.
            const click = async (name) => {
                const reloaded = page.waitForResponse((r) => /\/hrms\/skills$/.test(r.url()) && r.request().method() === 'GET', { timeout: 8000 });
                await row.getByRole('button', { name }).click({ timeout: 8000 });
                await reloaded;
            };
            if (await row.getByRole('button', { name: 'Restore' }).count()) await click('Restore');
            await click('Archive');
            await expect(row.getByText('Archived', { exact: true })).toBeVisible({ timeout: 8000 });
            await click('Restore');
            await expect(row.getByText('Archived', { exact: true })).toHaveCount(0, { timeout: 8000 });

            await row.getByRole('button', { name: 'Delete' }).click({ timeout: 8000 });
            await page.getByRole('dialog').filter({ hasText: 'Delete this skill?' }).getByRole('button', { name: 'Delete' }).click();
            await expect(skillRow(page, TEMP)).toHaveCount(0, { timeout: 8000 });
        });
        await openSkills(page, 'Catalogue');
        await expect(skillRow(page, TEMP)).toHaveCount(0);
    });

    test('Employee: sets their own level, saves, and it persists', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await openSkills(page);
        const select = page.getByLabel(KEEP, { exact: true });
        await expect(select).toBeVisible();
        test.skip(await select.isDisabled(), 'already locked by a manager assessment on an earlier run');

        await select.selectOption('3');
        await page.getByRole('button', { name: 'Save my skills' }).click();
        await expect(page.getByText('Your skills were saved.')).toBeVisible({ timeout: 8000 });
        await openSkills(page);
        await expect(page.getByLabel(KEEP, { exact: true })).toHaveValue('3');
    });

    test('business rule: a skill someone holds cannot be deleted', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openSkills(page, 'Catalogue');
        await expect(skillRow(page, KEEP)).toContainText(/1 person/);
        await expect(skillRow(page, KEEP).getByRole('button', { name: 'Delete' })).toBeDisabled();
    });

    test('matrix: a manager assesses the Employee; the self-declared level becomes locked for them', async ({ page }) => {
        await useSession(page, TENANT_ADMIN);
        await openSkills(page, 'Team matrix');
        const cell = page.getByLabel(`QA Employee · ${KEEP}`);
        await expect(cell).toBeVisible({ timeout: 8000 });
        if ((await cell.inputValue()) !== '4') {
            await cell.selectOption('4');
            await expect(page.getByText('Level recorded.')).toBeVisible({ timeout: 8000 });
        }
        await openSkills(page, 'Team matrix');
        await expect(page.getByLabel(`QA Employee · ${KEEP}`)).toHaveValue('4');

        await useSession(page, PERSONAS.employee);
        await openSkills(page);
        await expect(page.getByLabel(KEEP, { exact: true })).toBeDisabled();
        await expect(page.getByText('Assessed', { exact: true }).first()).toBeVisible();
    });

    test('permissions: the Employee has no catalogue or assessment rights on the API', async ({ page }) => {
        await useSession(page, PERSONAS.employee);
        await page.goto('/app/hrms/skills');
        expect((await apiCall(page, 'POST', '/hrms/skills', { name: 'QA-PF-Forbidden' })).status()).toBe(403);
        const me = await (await apiCall(page, 'GET', '/my/hr')).json();
        const other = me.profile.id === 1 ? 2 : 1;
        expect((await apiCall(page, 'PUT', `/hrms/employees/${other}/skills`, { skills: [{ skill_id: 1, level: 5 }] })).status()).toBe(403);
    });
});
