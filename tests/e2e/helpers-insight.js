import fs from 'node:fs';
import { PERSONAS, TENANT_ADMIN, useSession } from './helpers';

export { PERSONAS, TENANT_ADMIN, useSession };

/** GET a JSON API path through the page's own session (same cookies the SPA uses). */
export async function apiGet(page, path) {
    return page.evaluate(async (p) => {
        const r = await fetch(`/api${p}`, { headers: { Accept: 'application/json' }, credentials: 'include' });
        let body = null;
        try { body = await r.json(); } catch { /* not json */ }
        return { status: r.status, body };
    }, path);
}

export const sessionFileExists = (persona) => fs.existsSync(`tests/e2e/.auth/${persona.email}.json`);

/** Same-origin write through the page's session, sending the XSRF header the SPA's axios would. */
export async function apiSend(page, method, path, body) {
    return page.evaluate(async ({ method, path, body }) => {
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch(`/api${path}`, {
            method,
            credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: body ? JSON.stringify(body) : undefined,
        });
        let json = null;
        try { json = await r.json(); } catch { /* none */ }
        return { status: r.status, body: json };
    }, { method, path, body });
}

/**
 * A narrow persona: `hrms.view` + the listed permissions only. Created once per slug (idempotent),
 * as the tenant admin, so tab/route gating can be checked against a role the built-ins do not cover.
 */
export async function ensureNarrowPersona(adminPage, { slug, name, email, permissions }) {
    const existing = await apiGet(adminPage, '/users?search=' + encodeURIComponent(email));
    if (JSON.stringify(existing.body ?? {}).includes(email)) return;

    const roles = await apiGet(adminPage, '/roles');
    const all = roles.body.roles.find((r) => r.slug === 'admin').permissions;
    const ids = ['hrms.view', ...permissions].map((s) => all.find((p) => p.slug === s)?.id).filter(Boolean);
    let role = roles.body.roles.find((r) => r.slug === slug);
    if (!role) {
        const made = await apiSend(adminPage, 'POST', '/roles', { name, slug, permissions: ids });
        if (made.status >= 300) throw new Error(`role create failed ${made.status} ${JSON.stringify(made.body)}`);
        role = made.body.role ?? made.body;
    }
    const user = await apiSend(adminPage, 'POST', '/users', { name, email, password: 'password', password_confirmation: 'password', roles: [slug] });
    if (user.status >= 300) throw new Error(`user create failed ${user.status} ${JSON.stringify(user.body)}`);
}
