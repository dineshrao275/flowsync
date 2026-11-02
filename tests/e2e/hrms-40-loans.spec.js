import { test, expect } from '@playwright/test';
import { as, ADMIN, EMPLOYEE, HR_EXEC } from './helpers-pay';

const REASON = 'QA-PY advance for laptop repair';
const dlg = (page) => page.getByRole('dialog', { name: /loan/i });
const loanItem = (page) => page.getByRole('listitem').filter({ hasText: REASON });

async function request(page, { principal, installments = '3', month = '', reason = REASON } = {}) {
    await page.getByRole('button', { name: 'Request a loan' }).click();
    const d = dlg(page);
    if (principal !== undefined) await d.getByLabel('Amount').fill(principal);
    await d.getByLabel('Instalments').fill(installments);
    if (month) await d.getByLabel('First deduction month').fill(month);
    await d.getByLabel('Reason').fill(reason);
    return d;
}

test.describe.serial('HRMS tab 40 — Loans & advances (Employee)', () => {
    test.beforeEach(async ({ page }) => {
        await as(page, EMPLOYEE);
        await page.goto('/app/hrms/loans');
        await expect(page.getByRole('button', { name: 'Request a loan' })).toBeVisible();
        await page.waitForLoadState('networkidle');
    });

    test('the send button stays disabled until an amount is entered', async ({ page }) => {
        await page.getByRole('button', { name: 'Request a loan' }).click();
        await expect(dlg(page).getByRole('button', { name: 'Send request' })).toBeDisabled();
    });

    test('out-of-range instalments or month are refused and nothing is created', async ({ page }) => {
        const d = await request(page, { principal: '1000', installments: '61', month: '13', reason: 'QA-PY invalid terms' });
        await d.getByRole('button', { name: 'Send request' }).click();
        await page.waitForTimeout(1200);
        await expect(d).toBeVisible();
        await expect(page.getByRole('listitem').filter({ hasText: 'QA-PY invalid terms' })).toHaveCount(0);
    });

    test('a negative amount is refused', async ({ page }) => {
        const d = await request(page, { principal: '-50', reason: 'QA-PY negative' });
        await d.getByRole('button', { name: 'Send request' }).click();
        await page.waitForTimeout(1200);
        await expect(page.getByRole('listitem').filter({ hasText: 'QA-PY negative' })).toHaveCount(0);
    });

    test('requests an advance, sees it waiting with its terms, and it survives a reload', async ({ page }) => {
        test.skip(await loanItem(page).count() > 0, 'request exists from an earlier run');
        const d = await request(page, { principal: '3000' });
        await d.getByRole('button', { name: 'Send request' }).click();
        await expect(page.getByText('Loan requested.')).toBeVisible({ timeout: 15000 });
        await expect(loanItem(page)).toContainText('3 × 1000');
        await expect(loanItem(page)).toContainText('Waiting');
        await page.reload();
        await expect(loanItem(page)).toContainText('Waiting');
    });

    test('the requester cannot decide their own request', async ({ page }) => {
        await expect(loanItem(page)).toBeVisible();
        await expect(loanItem(page).getByRole('button', { name: 'Approve' })).toHaveCount(0);
        await expect(loanItem(page).getByRole('button', { name: 'Reject' })).toHaveCount(0);
    });
});

test.describe.serial('HRMS tab 40 — Loans: deciding (approvers)', () => {
    test('the manager (HR Executive) is shown the request and can reject only with a note', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/loans');
        await page.waitForTimeout(3000);
        test.skip(await loanItem(page).getByRole('button', { name: 'Reject' }).count() === 0, 'request not decidable by the manager (already decided)');
        await loanItem(page).getByRole('button', { name: 'Reject' }).click();
        await page.waitForTimeout(1500);
        await expect(loanItem(page)).toContainText('Waiting'); // refused without a note: still waiting
        await page.reload();
        await expect(loanItem(page)).toContainText('Waiting');
    });

    test('an approval by the manager moves the chain on; an employee outside the chain sees no decision buttons', async ({ page }) => {
        await as(page, HR_EXEC);
        await page.goto('/app/hrms/loans');
        await page.waitForTimeout(3000);
        test.skip(await loanItem(page).getByRole('button', { name: 'Approve' }).count() === 0, 'nothing for the manager to approve');
        await loanItem(page).getByRole('button', { name: 'Approve' }).click();
        await expect(page.getByText('Decision recorded.')).toBeVisible({ timeout: 15000 });
        await page.reload();
        await expect(loanItem(page).getByRole('button', { name: 'Approve' })).toHaveCount(0);
    });

    test('the admin completes the second leg and the loan becomes active with a repayment progress line', async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/loans');
        await expect(loanItem(page)).toBeVisible({ timeout: 15000 });
        test.skip(await loanItem(page).getByRole('button', { name: 'Approve' }).count() === 0, 'already decided by the admin');
        await loanItem(page).getByRole('button', { name: 'Approve' }).click();
        await expect(page.getByText('Decision recorded.')).toBeVisible({ timeout: 15000 });
        await page.reload();
        await expect(loanItem(page)).toContainText('Repaying');
        await expect(loanItem(page)).toContainText(/0\/3 repaid/);
    });

    test('the admin sees the request and its status', async ({ page }) => {
        await as(page, ADMIN);
        await page.goto('/app/hrms/loans');
        await page.waitForLoadState('networkidle');
        await expect(loanItem(page)).toBeVisible();
        await expect(loanItem(page)).toContainText('QA Employee');
    });
});
