import fs from 'node:fs';
import { test } from '@playwright/test';
import { useSession } from './helpers.js';

/**
 * Real-browser contrast audit: every shipped theme preset plus two hostile custom themes, in light and dark,
 * across the main screens, checked with axe-core's `color-contrast` rule on the RENDERED page (so it sees the
 * actual CSS, the resolved theme variables and any Tailwind class that bypasses them).
 *
 *   THEME_AUDIT=1 npx playwright test tests/e2e/theme-contrast.spec.js
 *
 * Writes tests/e2e/.results/theme-contrast.json and prints a summary. Opt-in: it signs in once and PUTs the
 * theme of the demo admin repeatedly, so it is not part of the default e2e run.
 */
const RUN = process.env.THEME_AUDIT === '1';
const ADMIN = { email: 'admin@flowsync.test', password: 'password' };

const BASE = { sidebar_bg: '#0f172a', sidebar_hover: '#1e293b', active_menu: '#2563eb', sidebar_text: '#cbd5e1', dashboard_bg: '#f1f5f9', header_bg: '#ffffff', header_text: '#0f172a', card_bg: '#ffffff', accent: '#2563eb' };
const THEMES = [
    ['Indigo Slate', BASE],
    ['Emerald Dark', { ...BASE, sidebar_bg: '#022c22', sidebar_hover: '#064e3b', active_menu: '#10b981', sidebar_text: '#a7f3d0', dashboard_bg: '#ecfdf5', header_text: '#022c22', accent: '#10b981' }],
    ['Rose Night', { ...BASE, sidebar_bg: '#1c1917', sidebar_hover: '#292524', active_menu: '#f43f5e', sidebar_text: '#fda4af', dashboard_bg: '#fff1f2', header_text: '#1c1917', accent: '#f43f5e' }],
    ['Blue Steel', { ...BASE, sidebar_bg: '#0c4a6e', sidebar_hover: '#075985', active_menu: '#38bdf8', sidebar_text: '#bae6fd', dashboard_bg: '#f0f9ff', header_text: '#0c4a6e', accent: '#0284c7' }],
    ['Custom: neon on pale', { ...BASE, accent: '#ffff00', active_menu: '#fff200', sidebar_bg: '#fafafa', sidebar_hover: '#f0f0f0', sidebar_text: '#f5f5f5', header_bg: '#fafafa', header_text: '#f0f0f0' }],
    ['Custom: dark card, light mode', { ...BASE, card_bg: '#10131a', dashboard_bg: '#fafafa', header_bg: '#10131a', header_text: '#10131a' }],
];
const PAGES = ['/app/dashboard', '/app/workspaces', '/app/projects', '/app/users', '/app/roles', '/app/settings', '/app/notifications', '/app/reports', '/app/webhooks', '/app/support', '/app/audit', '/app/subscription', '/app/integrations', '/app/hrms', '/app/hrms/employees', '/app/hrms/leave', '/app/hrms/attendance', '/app/hrms/expenses'];

/**
 * Swaps the theme in the session payload the SPA boots from. The tenant PUT /api/theme needs the `branding`
 * plan module, which the demo plans do not all include, and the audit must not depend on (or change) billing.
 */
async function setTheme(page, theme, mode) {
    await page.unroute('**/api/auth/me').catch(() => {});
    await page.route('**/api/auth/me', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.theme = { ...theme, mode };
        await route.fulfill({ response, json: body });
    });
}

test.describe('theme contrast (real browser)', () => {
    test.skip(!RUN, 'opt-in: THEME_AUDIT=1');
    test.setTimeout(30 * 60_000);

    test('axe color-contrast across themes, modes and screens', async ({ page }) => {
        await useSession(page, ADMIN);
        await page.goto('/app/dashboard');
        const axeSource = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
        const pages = [...PAGES];
        const projects = await page.request.get('/api/projects?per_page=1').then((r) => r.json()).catch(() => null);
        const projectId = projects?.projects?.[0]?.id ?? projects?.data?.[0]?.id;
        if (projectId) pages.push(`/app/projects/${projectId}`, `/app/projects/${projectId}?tab=settings`);

        // AUDIT_THEMES=0,1 limits the run to those theme indexes (default: all).
        const only = process.env.AUDIT_THEMES ? process.env.AUDIT_THEMES.split(',').map(Number) : null;
        const report = [];
        for (const [index, [name, theme]] of THEMES.entries()) {
            if (only && !only.includes(index)) continue;
            for (const mode of ['light', 'dark']) {
                await setTheme(page, theme, mode);
                for (const path of pages) {
                    await page.goto(path, { waitUntil: 'networkidle' }).catch(() => {});
                    // Entrance animations fade text in from transparent; axe would read the half-faded colour.
                    await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; }' });
                    await page.waitForTimeout(1500);
                    // GSAP hero reveals animate inline styles (CSS overrides can't stop them): wait until settled.
                    await page.waitForFunction(() =>
                        [...document.querySelectorAll('[data-hero-reveal]')].every((el) => {
                            const st = getComputedStyle(el);
                            return st.opacity === '1' && (st.transform === 'none' || st.transform === 'matrix(1, 0, 0, 1, 0, 0)');
                        }), null, { timeout: 5000 }).catch(() => {});
                    await page.addScriptTag({ content: axeSource });
                    const result = await page.evaluate(async () => {
                        const r = await window.axe.run(document, { runOnly: ['color-contrast'], resultTypes: ['violations'] });
                        return r.violations.flatMap((v) => v.nodes.map((n) => ({
                            target: n.target.join(' '), html: n.html.slice(0, 140),
                            msg: (n.any[0]?.message ?? '').replace(/^Element has insufficient color contrast of /, ''),
                        })));
                    });
                    report.push({ theme: name, mode, path, count: result.length, nodes: result.slice(0, 12) });
                }
            }
        }

        fs.mkdirSync('tests/e2e/.results', { recursive: true });
        fs.writeFileSync('tests/e2e/.results/theme-contrast.json', JSON.stringify(report, null, 2));
        const total = report.reduce((n, r) => n + r.count, 0);
        const byCombo = {};
        report.forEach((r) => { byCombo[`${r.theme} / ${r.mode}`] = (byCombo[`${r.theme} / ${r.mode}`] ?? 0) + r.count; });
        console.log(`\nTHEME CONTRAST: ${total} violating nodes across ${report.length} page renders`);
        Object.entries(byCombo).forEach(([k, v]) => console.log(`  ${String(v).padStart(5)}  ${k}`));
    });
});
