import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, type Page, test } from '@playwright/test';

/*
 * UI-POLÍROZÁS — a @venture/ui 0.3.0 ÁTVÉTELE (1., 2., 3., 5., 8. pont): ELŐTTE / UTÁNA képek.
 *
 * NEM a CI-suite része (a `playwright.config.ts` a `screenshots.spec.ts` mintával kihagyja).
 *   ELŐTTE: a main (59a665fa) + @venture/ui 0.2.1 build egy külön szerveren (APP_URL), és
 *     SCREENSHOTS=1 UI_POLISH_PHASE=before APP_URL=http://127.0.0.1:8001 npx playwright test e2e/ui-polish-library-screenshots.spec.ts
 *   UTÁNA:  SCREENSHOTS=1 UI_POLISH_PHASE=after npx playwright test e2e/ui-polish-library-screenshots.spec.ts
 * Kimenet: `docs/screenshots/ui-polish/<phase>/<viewport>/L<pont>-<képernyő>.png`.
 */
const PHASE = process.env.UI_POLISH_PHASE === 'after' ? 'after' : 'before';
const OUT = resolve('docs/screenshots/ui-polish', PHASE);

const VIEWPORTS = {
    '1440x900': { width: 1440, height: 900 },
    '1280x800': { width: 1280, height: 800 },
    '390x844': { width: 390, height: 844 },
} as const;

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

test.beforeAll(async ({ browser }) => {
    for (const dir of Object.keys(VIEWPORTS)) mkdirSync(join(OUT, dir), { recursive: true });

    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    for (const seed of ['/dev/seed-logs', '/dev/seed-studio', '/dev/seed-analytics']) await page.goto(seed);

    await context.close();
});

async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(500);
}

async function shot(page: Page, dir: string, name: string, fullPage = false): Promise<void> {
    await settle(page);

    const unstick = fullPage ? await page.addStyleTag({ content: '[data-testid="app-nav"] { position: static !important; }' }) : null;

    await page.screenshot({ path: join(OUT, dir, `${name}.png`), fullPage, animations: 'disabled', caret: 'hide' });

    if (unstick) await unstick.evaluate((node) => (node as Element).remove());
}

for (const [dir, viewport] of Object.entries(VIEWPORTS)) {
    const mobile = viewport.width < 600;

    test.describe(dir, () => {
        test.use({ viewport });

        test('1 + 2: ikonos gombok és a kártya glow (áttekintő)', async ({ page }) => {
            await page.goto('/dashboard');
            await expect(page.getByTestId('dashboard-import')).toBeVisible();
            await shot(page, dir, 'L1-buttons');
            await shot(page, dir, 'L2-cards-overview', true);

            await page.goto('/profile');
            await shot(page, dir, 'L2-cards-profile', true);
        });

        test('3: aktív menüpont', async ({ page }) => {
            await page.goto('/domains');

            if (mobile) {
                await page.getByTestId('nav-hamburger').click();
                const group = page.getByTestId('nav-group-platform');

                if ((await group.getAttribute('aria-expanded')) !== 'true') await group.click();
            } else {
                await page.getByTestId('nav-group-platform').click();
            }

            await expect(page.getByTestId('nav-domains')).toBeVisible();
            await shot(page, dir, 'L3-active-nav');
        });

        test('5: fájlfeltöltés (import, branding)', async ({ page }) => {
            await page.goto('/audience/import');
            await page.getByRole('tab').nth(2).click(); // CSV
            await shot(page, dir, 'L5-upload-import');

            await page.goto('/settings/branding');
            await shot(page, dir, 'L5-upload-branding', true);
        });

        test('8: legördülő a lap alján (export-menü az analitikán)', async ({ page }) => {
            await page.goto('/analytics');
            await settle(page);

            const trigger = page.locator('[data-testid^="export-"][aria-haspopup="menu"]').last();

            await trigger.scrollIntoViewIfNeeded();
            await trigger.evaluate((node) => node.scrollIntoView({ block: 'end' }));
            await trigger.click();
            await page.waitForTimeout(300);
            await shot(page, dir, 'L8-dropdown-bottom');
        });
    });
}
