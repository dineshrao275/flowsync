export const TENANT_ADMIN = { email: 'owner@globex.test', password: 'password' };

export async function login(page, { email, password } = TENANT_ADMIN) {
    await page.goto('/app/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/^password/i).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(/\/app\/(?!login)/);
}
