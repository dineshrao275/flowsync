import fs from 'node:fs';
import { test, expect } from '@playwright/test';
import { as, ADMIN } from './helpers-pay';

// Every HRMS page, in English and Spanish, must show text, never a raw translation key
// (i18next prints the key itself when it is missing, e.g. "hrms.offboarding.stInProgress").
const PATHS = [...fs.readFileSync('resources/js/utils/hrmsNav.js', 'utf8').matchAll(/\{ to: '([^']+)'/g)].map((m) => m[1]);

function flatten(obj, prefix = '', out = new Set()) {
    for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object') flatten(v, key, out);
        else out.add(key);
    }
    return out;
}

const KEYS = new Set();
for (const file of fs.readdirSync('resources/js/locales/en')) {
    const data = JSON.parse(fs.readFileSync(`resources/js/locales/en/${file}`, 'utf8'));
    const base = file.replace('.json', '');
    for (const key of flatten(data)) { KEYS.add(key); KEYS.add(`${base}.${key}`); }
}

const setLocale = (page, locale) => page.evaluate(async (l) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const res = await fetch('/api/language', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ locale: l }) });
    return res.status;
}, locale);

test.describe.serial('HRMS pages never show a raw translation key', () => {
    test.setTimeout(900_000);

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: 'http://localhost:8000' });
        await as(page, ADMIN);
        await page.goto('/app/hrms');
        await setLocale(page, 'en'); // never leave the shared persona in Spanish
        await page.close();
    });

    for (const locale of ['en', 'es']) {
        test(`${locale}: all ${PATHS.length} pages`, async ({ page }) => {
            await as(page, ADMIN);
            await page.goto('/app/hrms');
            expect(await setLocale(page, locale)).toBeLessThan(300);

            const leaks = [];
            for (const path of PATHS) {
                await page.goto(`/app${path}`);
                await page.waitForLoadState('networkidle');
                await page.waitForTimeout(600);
                const text = await page.locator('body').innerText();
                // The overview prints module keys ('hrms.core') and the audit trail prints event names
                // ('roster.removed') as data, so neither can be told from a leaked key.
                if (path !== '/hrms' && path !== '/hrms/audit') {
                    for (const token of text.match(/[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+/g) ?? []) {
                        if (KEYS.has(token)) leaks.push(`${locale} ${path}: ${token}`);
                    }
                }
            }
            expect([...new Set(leaks)]).toEqual([]);
        });
    }
});
