import { defineConfig } from '@playwright/test';

// UI end-to-end tests run against the LOCAL Docker stack only (never production).
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 60_000,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    outputDir: 'tests/e2e/.results',
});
