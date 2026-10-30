export const TENANT_ADMIN = { email: 'owner@globex.test', password: 'password' };

export async function login(page, { email, password } = TENANT_ADMIN) {
    await page.goto('/app/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/^password/i).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(/\/app\/(?!login)/);
}

export const PERSONAS = {
    employee: { name: 'QA Employee', email: 'qa.employee@globex.test', password: 'password', role: 'Employee' },
    hrExec: { name: 'QA HR Executive', email: 'qa.hrexec@globex.test', password: 'password', role: 'HR Executive' },
};

// One real sign-in per persona, then the session cookies are reused: the login endpoint is
// throttled, so signing in before every test would (correctly) lock the suite out.
import fs from 'node:fs';

const AUTH_DIR = 'tests/e2e/.auth';

export async function useSession(page, persona = TENANT_ADMIN) {
    const file = `${AUTH_DIR}/${persona.email}.json`;
    if (!fs.existsSync(file)) {
        await login(page, persona);
        fs.mkdirSync(AUTH_DIR, { recursive: true });
        fs.writeFileSync(file, JSON.stringify(await page.context().cookies()));
        return;
    }
    await page.context().addCookies(JSON.parse(fs.readFileSync(file, 'utf8')));
}

export async function logout(page) {
    await page.context().clearCookies();
    await page.evaluate(() => { try { localStorage.clear(); sessionStorage.clear(); } catch { /* none */ } });
}
