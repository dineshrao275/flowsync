import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE } from './helpers-pay';

// FU-1: the approval chain of an expense claim is completed through the UI, then the claim is priced.
const PURPOSE = 'QA-FU1-Step claim';
const RECEIPT = '/tmp/claude-1000/-home-dineshrao-Public-flowsync/a031d850-6887-4bae-b576-18730569d466/scratchpad/files/receipt.pdf';
const claimRow = (page) => page.getByRole('row').filter({ hasText: PURPOSE });
const mineRow = (page) => page.getByRole('listitem').filter({ hasText: PURPOSE });

test.describe.serial('FU-1 — expense approval steps through the UI', () => {
    test('the employee files and submits a claim', async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/expenses/mine');
        test.skip(await mineRow(page).count() > 0, 'claim exists from an earlier run');

        await page.getByRole('button', { name: /file/i }).first().click();
        const d = page.getByRole('dialog', { name: /claim/i });
        await d.getByLabel('Claim date').fill('2026-05-12');
        await d.getByLabel('Purpose').fill(PURPOSE);
        await d.getByLabel('Year', { exact: true }).fill('2026');
        await d.getByLabel(/^Month/).fill('5');
        await d.getByLabel('Line 1 category').selectOption({ label: 'Meals' });
        await d.getByLabel('Amount').first().fill('500');
        await d.getByLabel('Description').first().fill('QA-FU1 line');
        await d.locator('input[type=file]').first().setInputFiles(RECEIPT);
        await d.getByRole('heading', { name: 'File a claim' }).click();
        await d.getByRole('button', { name: 'File claim' }).click();
        await mineRow(page).getByRole('button', { name: /^Submit/ }).click();
        await page.reload();
        await expect(mineRow(page)).not.toContainText(/draft/i);
    });

    test('an approver clears each leg (the override needs a reason), then the claim is priced and approved', async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/expenses');
        await expect(claimRow(page)).toBeVisible({ timeout: 20000 });
        test.skip(!(await claimRow(page).textContent())?.includes('submitted'), 'claim already decided in an earlier run');

        const open = async () => {
            await claimRow(page).getByRole('button', { name: 'Decide' }).click();
            const d = page.getByRole('dialog', { name: /EXP-/ });
            await expect(d.getByText('Your approval step')).toBeVisible({ timeout: 15000 });
            return d;
        };

        // Acting for the assigned approver is the audited override: no reason, no step.
        let d = await open();
        await d.getByRole('button', { name: 'Clear my step' }).click();
        await expect(d.getByText(/reason/i).first()).toBeVisible({ timeout: 15000 });
        await d.getByLabel(/^Note/).fill('QA FU-1 manager unavailable');
        await d.getByRole('button', { name: 'Clear my step' }).click();
        await expect(page.getByText('Approval step cleared.')).toBeVisible({ timeout: 15000 });
        await expect(claimRow(page)).toContainText('submitted');

        // Second leg.
        d = await open();
        await d.getByLabel(/^Note/).fill('QA FU-1 finance leg');
        await d.getByRole('button', { name: 'Clear my step' }).click();
        await expect(page.getByText('Approval step cleared.')).toBeVisible({ timeout: 15000 });

        // The chain is resolved, so the priced decision is accepted.
        await claimRow(page).getByRole('button', { name: 'Decide' }).click();
        d = page.getByRole('dialog', { name: /EXP-/ });
        await d.getByRole('button', { name: /record decision/i }).click();
        await expect(page.getByText('Claim approved.')).toBeVisible({ timeout: 15000 });

        await page.reload();
        await expect(claimRow(page)).toContainText('approved');
    });
});
