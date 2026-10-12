import { defineConfig, devices } from '@playwright/test';

/*
 | ÉLES ellenőrzés (deploy-ovh 5.1) — KÉZZEL futtatandó, a CI sosem futtatja:
 |   E2E_EMAIL=… E2E_PW=… npx playwright test -c playwright.prod.config.ts
 | Ideiglenes ventSSO teszt-userrel; a futás után a user és a tagsága törlődik.
 */
export default defineConfig({
    testDir: './e2e-prod',
    workers: 1,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: process.env.PROD_URL ?? 'https://app.ventmail.app',
        screenshot: 'on',
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
