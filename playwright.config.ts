import { defineConfig, devices } from '@playwright/test';

/* A flow-kat Playwright fedi; a unit/feature réteg Pest. */
export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: process.env.APP_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        // A dev stack Caddyje Basic Auth mögött van (golden rule 11) — APP_URL használatakor kell.
        httpCredentials:
            process.env.E2E_BASIC_AUTH_USER && process.env.E2E_BASIC_AUTH_PASS
                ? { username: process.env.E2E_BASIC_AUTH_USER, password: process.env.E2E_BASIC_AUTH_PASS }
                : undefined,
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

    /* Alapból saját szervert indít (auth nélkül); a dev stack Caddyje mögé APP_URL-lel lehet mutatni. */
    webServer: process.env.APP_URL
        ? undefined
        : {
              // Az e2e-szerver nem függ Redistől/Meilitől, de a session-nek PERZISZTENSNEK
              // kell lennie: `array` driverrel a bejelentkezés nem élné túl a következő kérést.
              command: 'SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=sync php artisan serve --port=8000',
              url: 'http://127.0.0.1:8000/up',
              reuseExistingServer: !process.env.CI,
              timeout: 60_000,
          },
});
