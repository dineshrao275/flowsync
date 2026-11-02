import fs from 'node:fs';

// Reuses the cookie files written by helpers.js `useSession` (never signs in).
export async function sessionPage(browser, email) {
    const ctx = await browser.newContext({ baseURL: 'http://localhost:8000' });
    await ctx.addCookies(JSON.parse(fs.readFileSync(`tests/e2e/.auth/${email}.json`, 'utf8')));
    return ctx.newPage();
}

export async function bodyText(page, n = 2500) {
    return (await page.locator('body').innerText({ timeout: 8000 }).catch(() => '')).replace(/\n{2,}/g, '\n').slice(0, n);
}

// Same-origin API call using the SPA session + CSRF cookie (setup/cleanup, never the thing under test).
export async function apiCall(page, method, url, body) {
    return page.evaluate(async ({ method, url, body }) => {
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const res = await fetch(`/api${url}`, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: body ? JSON.stringify(body) : undefined,
        });
        let json = null;
        try { json = await res.json(); } catch { /* empty body */ }
        return { status: res.status, json };
    }, { method, url, body });
}

// `useSession` trusts the cookie file forever; sessions do expire. This probes /api/auth/me once and only
// signs in again (one login, throttle-safe) when the saved cookie is dead.
import { useSession as baseSession, login as baseLogin, TENANT_ADMIN } from './helpers.js';

export async function useFreshSession(page, persona = TENANT_ADMIN) {
    await baseSession(page, persona);
    const alive = await page.request.get('/api/auth/me', { headers: { Accept: 'application/json' } }).then((r) => r.ok()).catch(() => false);
    if (alive) return;
    fs.rmSync(`tests/e2e/.auth/${persona.email}.json`, { force: true });
    await page.context().clearCookies();
    await baseLogin(page, persona);
    fs.mkdirSync('tests/e2e/.auth', { recursive: true });
    fs.writeFileSync(`tests/e2e/.auth/${persona.email}.json`, JSON.stringify(await page.context().cookies()));
}
