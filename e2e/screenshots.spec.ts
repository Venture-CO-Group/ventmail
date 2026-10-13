import { type ChildProcess, execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { expect, type Locator, type Page, test } from '@playwright/test';
import { startImportWorker } from './support/import-fixtures';

/*
 * v1.1 · 13. pont — KÉPERNYŐKÉPEK (leadandó): minden fő képernyő desktopon (1440×900) és
 * mobilon (390×844), a mockup mellé: `docs/screenshots/v1.1/{desktop,mobile}/<NN-screen>.png`,
 * plusz a mockup megfelelő képernyői: `docs/screenshots/v1.1/mockup/{desktop,mobile}/<id>.png`.
 *
 * NEM a CI-suite része (a képeket nem generáljuk újra minden futáson): a `playwright.config.ts`
 * kihagyja, hacsak nincs `SCREENSHOTS=1` — futtatás: `npm run screenshots`.
 *
 * CSAK seedelt demó-adat (a `/dev/seed-*` végpontok, `pelda.hu` / `example.test` címek, a
 * dev-felhasználó) — valódi személyes adat nincs. Az import-varázsló az ELŐNÉZETIG megy
 * (az „Import indítása" nem fut: a képernyőkép nem hoz létre kontaktot).
 *
 * A platform-admin „Infrastruktúra" képernyőhöz a dev-felhasználó IDEIGLENESEN platform-admin
 * jogot kap (a `users.platform_admin` cache-oszlop), és a `finally` ág azonnal visszaveszi.
 */

const OUT = resolve('docs/screenshots/v1.1');
const MOCKUP = pathToFileURL(resolve('docs/mockups/ventmail-v1.1.html')).href;

const VIEWPORTS = {
    desktop: { width: 1440, height: 900 },
    mobile: { width: 390, height: 844 },
} as const;

type Kind = keyof typeof VIEWPORTS;

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

let worker: ChildProcess | null = null;

test.beforeAll(async ({ browser }) => {
    for (const dir of ['desktop', 'mobile', 'mockup/desktop', 'mockup/mobile']) mkdirSync(join(OUT, dir), { recursive: true });

    worker = startImportWorker();

    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    // Idempotens demó-seedek: napló + domain, Studio, analitika (kampány, klikk-térkép, journey).
    for (const seed of ['/dev/seed-logs', '/dev/seed-studio', '/dev/seed-analytics']) await page.goto(seed);

    await context.close();
});

test.afterAll(() => {
    worker?.kill();
});

/** Nyugalmi állapot: hálózat, betűk, animációk vége. */
async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(400);
    // Az ECharts a beúszó animáció UTÁN rajzol teljesen: a túl korai kép üres grafikont mutatott.
    if ((await page.locator('canvas').count()) > 0) await page.waitForTimeout(1500);
}

async function shot(page: Page, kind: Kind, name: string, options: { fullPage?: boolean } = {}): Promise<void> {
    await settle(page);
    await page.screenshot({ path: join(OUT, kind, `${name}.png`), fullPage: options.fullPage ?? true, animations: 'disabled', caret: 'hide' });
}

async function visit(page: Page, path: string): Promise<void> {
    await page.goto(path);
    await expect(page.getByTestId('app-nav')).toBeVisible();
}

/** Egy lista első, adott szöveget tartalmazó linkje (a demó-sor), különben az első link. */
async function hrefOf(page: Page, prefix: string, text: string): Promise<string> {
    const preferred = page.locator(`a[href^="${prefix}"]`, { hasText: text }).first();
    const link: Locator = (await preferred.count()) > 0 ? preferred : page.locator(`a[href^="${prefix}"]`).first();
    const href = await link.getAttribute('href');

    if (!href) throw new Error(`nincs ${prefix} link`);

    return href;
}

function artisan(code: string): void {
    execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: process.cwd(), stdio: 'ignore' });
}

const DEV_ADMIN = (flag: boolean) =>
    `\\App\\Models\\User::query()->where('sso_issuer', 'https://dev.invalid')->where('sso_subject', 'dev-user')->update(['platform_admin' => ${flag ? 'true' : 'false'}]);`;

for (const kind of ['desktop', 'mobile'] as const) {
    test.describe(`${kind} (${VIEWPORTS[kind].width}×${VIEWPORTS[kind].height})`, () => {
        test.use({ viewport: VIEWPORTS[kind] });

        test('héj: menük, workspace-váltó, avatar-menü, nyelvválasztó', async ({ page }) => {
            await visit(page, '/dashboard');

            if (kind === 'desktop') {
                for (const [index, group] of [
                    ['01', 'studio'],
                    ['02', 'platform'],
                    ['03', 'settings'],
                ] as const) {
                    await page.getByTestId(`nav-group-${group}`).click();
                    await expect(page.getByTestId(`nav-group-${group}`)).toHaveAttribute('aria-expanded', 'true');
                    await shot(page, kind, `${index}-shell-${group}-menu`, { fullPage: false });
                    await page.keyboard.press('Escape');
                }
            } else {
                const burger = page.getByTestId('nav-hamburger');

                await burger.click();
                await expect(page.getByTestId('nav-mobile')).toBeVisible();

                for (const [index, group] of [
                    ['01', 'studio'],
                    ['02', 'platform'],
                    ['03', 'settings'],
                ] as const) {
                    const trigger = page.getByTestId(`nav-group-${group}`);

                    if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click();
                    await shot(page, kind, `${index}-shell-${group}-menu`, { fullPage: false });
                    await trigger.click(); // zár: a következő csoport tiszta panelen nyíljon
                }

                await page.keyboard.press('Escape');
            }

            await page.getByTestId('workspace-switch').click();
            await expect(page.getByTestId('workspace-list')).toBeVisible();
            await shot(page, kind, '04-shell-workspace-switcher', { fullPage: false });
            await page.keyboard.press('Escape');

            await page.getByTestId('user-menu').click();
            await expect(page.getByTestId('user-name')).toBeVisible();
            await shot(page, kind, '05-shell-avatar-menu', { fullPage: false });
            await page.keyboard.press('Escape');

            if (kind === 'desktop') {
                await page.getByTestId('locale-switch').click();
            } else {
                // Mobilon a nyelvválasztó a hamburger-panelben él.
                await page.getByTestId('nav-hamburger').click();
                await page.getByTestId('locale-option-fr').scrollIntoViewIfNeeded();
            }

            await expect(page.getByTestId('locale-option-fr')).toBeVisible();
            await shot(page, kind, '06-shell-language-picker', { fullPage: false });
            await page.keyboard.press('Escape');
        });

        test('áttekintés és közönség', async ({ page }) => {
            await visit(page, '/dashboard');
            await shot(page, kind, '07-overview');

            await visit(page, '/audience');
            await shot(page, kind, '08-audience');
        });

        test('import-varázsló lépései (az előnézetig)', async ({ page }) => {
            await visit(page, '/audience/import');
            await expect(page.getByTestId('import-wizard')).toBeVisible();
            await shot(page, kind, '09-import-1-source');

            await page.getByRole('tab').nth(1).click();
            await page.getByTestId('import-paste').fill(['anna.kiss@pelda.hu', 'gabor.toth@pelda.hu', 'info@pelda.hu', 'nem-cim'].join('\n'));
            await page.getByTestId('import-next').click();

            await expect(page.getByTestId('import-step-columns')).toBeVisible({ timeout: 60_000 });
            await shot(page, kind, '10-import-2-columns');

            await page.getByTestId('import-next').click();
            await expect(page.getByTestId('import-step-settings')).toBeVisible();
            await page.getByTestId('legal-unknown').locator('label').click();
            await shot(page, kind, '11-import-3-settings');

            await page.getByTestId('import-next').click();
            await expect(page.getByTestId('preview-importable')).toBeVisible({ timeout: 120_000 });
            await shot(page, kind, '12-import-4-preview');
        });

        test('sablonok és szerkesztő', async ({ page }) => {
            await visit(page, '/templates');
            await shot(page, kind, '13-templates');

            await page.goto(await hrefOf(page, '/templates/', 'Demó hírlevél'));
            await expect(page.getByTestId('app-nav')).toBeVisible();
            await shot(page, kind, '14-template-editor', { fullPage: false });
        });

        test('kampány', async ({ page }) => {
            await visit(page, '/campaigns');
            await shot(page, kind, '15-campaigns');

            await visit(page, `/campaigns?search=${encodeURIComponent('Őszi újdonságok')}`);
            await page.goto(await hrefOf(page, '/campaigns/', 'Őszi újdonságok'));
            await expect(page.getByTestId('app-nav')).toBeVisible();
            await shot(page, kind, '16-campaign');
        });

        test('journey-vászon és űrlapok', async ({ page }) => {
            await visit(page, '/journeys');
            await page.goto(await hrefOf(page, '/journeys/', 'Üdvözlő sorozat'));
            await expect(page.getByTestId('journey-canvas')).toBeVisible();
            await shot(page, kind, '17-journey-canvas', { fullPage: false });

            await visit(page, '/forms');
            await shot(page, kind, '18-forms');
        });

        test('Platform: domainek, API-kulcsok, webhookok', async ({ page }) => {
            await visit(page, '/domains');
            await shot(page, kind, '19-platform-domains');

            await visit(page, '/api-keys');
            await shot(page, kind, '20-api-keys');

            await visit(page, '/webhooks');
            await shot(page, kind, '21-webhooks');
        });

        test('Platform: napló + trace, suppression, statisztika, fejlesztői doksi', async ({ page }) => {
            await visit(page, '/logs');
            await shot(page, kind, '22-logs');

            await page.getByTestId('log-trace').first().click();
            await page.waitForURL(/\/logs\/[0-9a-f-]{36}/);
            await shot(page, kind, '23-log-trace');

            await visit(page, '/suppressions');
            await shot(page, kind, '24-suppression');

            await visit(page, '/statistics');
            await shot(page, kind, '25-statistics');

            await visit(page, '/developers');
            await shot(page, kind, '26-developers');
        });

        test('Infrastruktúra (platform-admin)', async ({ page }) => {
            artisan(DEV_ADMIN(true));

            try {
                await visit(page, '/platform/infrastructure');
                await shot(page, kind, '27-infrastructure');
            } finally {
                artisan(DEV_ADMIN(false));
            }
        });

        test('analitika (áttekintés + kampány hőtérképpel), branding, profil', async ({ page }) => {
            // A demó-kampány explicit: alapból a legutóbb kiküldött nyílna (egy e2e-maradék is lehet).
            await visit(page, `/campaigns?search=${encodeURIComponent('Őszi újdonságok')}`);
            const uuid = (await hrefOf(page, '/campaigns/', 'Őszi újdonságok')).split('/').pop();

            await visit(page, `/analytics?range=90&campaign=${uuid}`);
            await expect(page.getByTestId('chart-open-click')).toBeVisible();
            await expect(page.getByTestId('heatmap-frame')).toBeVisible();
            await shot(page, kind, '28-analytics');

            await visit(page, `/analytics/campaigns/${uuid}`);
            await expect(page.getByTestId('heatmap-frame')).toBeVisible();
            await shot(page, kind, '29-analytics-campaign');

            await visit(page, '/settings/branding');
            await shot(page, kind, '30-branding');

            await visit(page, '/profile');
            await shot(page, kind, '31-profile');
        });

        test('mockup: a megfelelő képernyők', async ({ page }) => {
            await page.goto(`${MOCKUP}#overview`);
            await page.waitForFunction(() => typeof (window as unknown as { go?: unknown }).go === 'function');

            const go = async (id: string) => {
                await page.evaluate((screen) => (window as unknown as { go: (s: string) => void }).go(screen), id);
                await page.waitForTimeout(700);
            };
            const mock = async (name: string) => {
                await page.evaluate(() => document.fonts.ready);
                await page.screenshot({ path: join(OUT, 'mockup', kind, `${name}.png`), fullPage: true, animations: 'disabled' });
            };

            for (const id of [
                'shell',
                'overview',
                'audience',
                'templates',
                'editor',
                'campaign',
                'journey',
                'platform-domains',
                'platform-keys',
                'platform-webhooks',
                'platform-logs',
                'platform-suppression',
                'platform-docs',
                'analytics',
                'branding',
                'profile',
            ]) {
                await go(id);
                await mock(id);
            }

            await go('import');
            for (const step of [1, 2, 3, 4]) {
                await page.evaluate((n) => (window as unknown as { istep: (n: number) => void }).istep(n), step);
                await page.waitForTimeout(300);
                await mock(`import-step${step}`);
            }
        });
    });
}
