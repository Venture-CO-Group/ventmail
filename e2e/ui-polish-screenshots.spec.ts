import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, type Locator, type Page, test } from '@playwright/test';

/*
 * UI-POLÍROZÁS (4., 6., 7., 9. pont) — ELŐTTE / UTÁNA képernyőképek.
 *
 * NEM a CI-suite része: a `playwright.config.ts` a `screenshots.spec.ts` mintával kihagyja,
 * hacsak nincs `SCREENSHOTS=1`. Futtatás:
 *   SCREENSHOTS=1 UI_POLISH_PHASE=before npx playwright test e2e/ui-polish-screenshots.spec.ts
 *   SCREENSHOTS=1 UI_POLISH_PHASE=after  npx playwright test e2e/ui-polish-screenshots.spec.ts
 *
 * Tiszta demó-DB (`migrate:fresh --seed`, CSAK a helyi dev DB) + a spec saját demó-seedjei.
 * Kimenet: `docs/screenshots/ui-polish/<phase>/<viewport>/<NN-screen>.png`.
 */
const PHASE = process.env.UI_POLISH_PHASE === 'after' ? 'after' : 'before';
const OUT = resolve('docs/screenshots/ui-polish', PHASE);

const VIEWPORTS = {
    '1440x900': { width: 1440, height: 900 },
    '1280x800': { width: 1280, height: 800 },
    '390x844': { width: 390, height: 844 },
} as const;

const LONG_NAME = 'UI-polish hosszú hírlevél';

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

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
    await page.waitForTimeout(400);
    if ((await page.locator('canvas').count()) > 0) await page.waitForTimeout(1500);
}

async function shot(page: Page, dir: string, name: string, fullPage = true): Promise<void> {
    await settle(page);

    const viewport = page.viewportSize();

    if (fullPage && viewport && (await page.locator('canvas').count()) > 0) {
        const height = await page.evaluate(() => document.documentElement.scrollHeight);
        await page.setViewportSize({ width: viewport.width, height: Math.max(viewport.height, height) });
        await page.waitForTimeout(1200);
    }

    await page.screenshot({ path: join(OUT, dir, `${name}.png`), fullPage, animations: 'disabled', caret: 'hide' });

    if (viewport) await page.setViewportSize(viewport);
}

async function visit(page: Page, path: string): Promise<void> {
    await page.goto(path);
    await expect(page.getByTestId('app-nav')).toBeVisible();
}

async function hrefOf(page: Page, prefix: string, text: string): Promise<string> {
    const preferred = page.locator(`a[href^="${prefix}"]`, { hasText: text }).first();
    const link: Locator = (await preferred.count()) > 0 ? preferred : page.locator(`a[href^="${prefix}"]`).first();
    const href = await link.getAttribute('href');

    if (!href) throw new Error(`nincs ${prefix} link`);

    return href;
}

for (const [dir, viewport] of Object.entries(VIEWPORTS)) {
    test.describe(dir, () => {
        test.use({ viewport });

        test('áttekintés (kártyarács)', async ({ page }) => {
            await visit(page, '/dashboard');
            await shot(page, dir, '01-overview');
        });

        test('kampány: létrehozás + tartalom lépés', async ({ page }) => {
            await visit(page, '/campaigns');
            await page.getByTestId('campaign-new').click();
            await shot(page, dir, '02-campaign-create', false);
            await page.keyboard.press('Escape');

            await visit(page, `/campaigns?search=${encodeURIComponent('Őszi újdonságok')}`);
            await page.goto(await hrefOf(page, '/campaigns/', 'Őszi újdonságok'));
            await expect(page.getByTestId('app-nav')).toBeVisible();

            const content = page.getByTestId('section-toggle-content');

            if ((await content.getAttribute('aria-expanded')) !== 'true') await content.click();
            await shot(page, dir, '03-campaign-content');
        });

        test('sablonszerkesztő hosszú levéllel', async ({ page }) => {
            await visit(page, '/templates');
            await page.goto(await hrefOf(page, '/templates/', 'Demó hírlevél'));
            await expect(page.getByTestId('editor')).toBeVisible();
            await shot(page, dir, '04-template-editor-viewport', false);
            await shot(page, dir, '05-template-editor-full');

            // HOSSZÚ (több képernyős) levél: a hírlevél-indulósablon, kétszer egymás után.
            await visit(page, '/templates');
            const long = page.locator('a[href^="/templates/"]', { hasText: LONG_NAME }).first();

            if ((await long.count()) > 0) {
                await page.goto((await long.getAttribute('href')) as string);
            } else {
                await page.getByTestId('starter-newsletter').click();
                await page.getByTestId('template-name').fill(LONG_NAME);
                await page.getByTestId('template-submit').click();
                await page.waitForURL(/\/templates\/[0-9a-f-]{36}$/);
            }
            await expect(page.getByTestId('editor')).toBeVisible();
            await shot(page, dir, '07-long-template-viewport', false);
            await shot(page, dir, '08-long-template-full');

            // Kijelölt elem: a jobb (beállítások) panel kinyílik (az „utána" állapotban; előtte mindig látszott).
            await page.getByTestId('block-1-0-0').click();
            await shot(page, dir, '09-editor-selected', false);
        });

        test('fejlesztői doksi', async ({ page }) => {
            await visit(page, '/developers');
            await shot(page, dir, '06-developer-docs');
        });
    });
}
