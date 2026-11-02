import fs from 'node:fs';
import { test } from '@playwright/test';
import { useSession, PERSONAS, TENANT_ADMIN } from './helpers';

async function signIn(page, { email, password }, timeout) {
    await page.goto('/app/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/^password/i).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(/\/app\/(?!login)/, { timeout });
}

export const HR_EXEC = PERSONAS.hrExec;
export const EMPLOYEE = PERSONAS.employee;
export const ADMIN = TENANT_ADMIN;

// Collects failing API calls (>=400) made while a page is open.
export function watchApi(page) {
    const errs = [];
    page.on('response', (r) => {
        if (r.url().includes('/api/') && r.status() >= 400) errs.push(`${r.status()} ${r.request().method()} ${r.url().split('/api/')[1].split('?')[0]}`);
    });
    return errs;
}

// Reuses the cached session, but if the server no longer honours it (expiry, or another run signed the
// persona out) signs in once more and refreshes the cache, so one stale cookie cannot fail a whole spec.
export async function as(page, persona) {
    await useSession(page, persona);
    const alive = await page.request.get('/api/auth/me', { headers: { Accept: 'application/json' } });
    if (alive.status() === 200) return;

    test.setTimeout(240000);
    await page.context().clearCookies();
    try {
        await signIn(page, persona, 15000);
    } catch {
        // "Too Many Attempts": the login throttle is per minute, so wait one window and try once more.
        await page.waitForTimeout(65000);
        await signIn(page, persona, 30000);
    }
    fs.writeFileSync(`tests/e2e/.auth/${persona.email}.json`, JSON.stringify(await page.context().cookies()));
}
