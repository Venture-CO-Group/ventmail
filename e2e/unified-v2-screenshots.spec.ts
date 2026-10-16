import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { expect, type Page, test } from '@playwright/test';

/*
 * UNIFIED UI v2 · KÉPERNYŐKÉPEK a Figma-képernyők mellé („Venture Apps — Unified UI v2",
 * 14 · ventMail oldal, 26 frame, 1440 széles): `docs/screenshots/unified-v2/app/<NN-név>.png`,
 * a Figma-render a `docs/screenshots/unified-v2/figma/<NN-név>.png` alatt (a Figma MCP-ből).
 * A párosítás és az eltérések: `docs/screenshots/unified-v2/README.md`.
 *
 * NEM a CI-suite része (a `playwright.config.ts` kihagyja, hacsak nincs `SCREENSHOTS=1`):
 * `SCREENSHOTS=1 npx playwright test e2e/unified-v2-screenshots.spec.ts`. Csak seedelt demó-adat.
 */

const OUT = resolve('docs/screenshots/unified-v2/app');

/** [fájlnév, Figma frame, útvonal] — a Figma-oldal sorrendjében. */
const SCREENS: [string, string, string][] = [
    ['01-overview', 'Mail · Overview', '/dashboard'],
    ['02-audience', 'Mail · Audience', '/audience'],
    ['03-segments', 'Mail · Segments', '/audience/segments'],
    ['04-forms', 'Mail · Forms', '/forms'],
    ['05-templates', 'Mail · Templates', '/templates'],
    ['06-campaigns', 'Mail · Campaigns', '/campaigns'],
    ['07-campaign-composer', 'Mail · Campaign composer', 'composer'],
    ['08-journeys', 'Mail · Journeys', '/journeys'],
    ['09-analytics', 'Mail · Analytics', '/analytics'],
    ['10-domains', 'Mail · Domains', '/domains'],
    ['11-api-keys', 'Mail · API keys', '/api-keys'],
    ['12-smtp-credentials', 'Mail · SMTP credentials', '/smtp-credentials'],
    ['13-suppressions', 'Mail · Suppression list', '/suppressions'],
    ['14-webhooks', 'Mail · Webhooks', '/webhooks'],
    ['15-stored-templates', 'Mail · Stored templates', '/stored-templates'],
    ['16-ip-pools', 'Mail · IP pools', '/ip-pools'],
    ['17-deliverability', 'Mail · Deliverability', '/deliverability'],
    ['18-log', 'Mail · Log', '/logs'],
    ['19-tag-statistics', 'Mail · Tag statistics', '/logs/statistics'],
    ['20-sending-statistics', 'Mail · Sending statistics', '/statistics'],
    ['21-developer-docs', 'Mail · Developer docs', '/developers'],
    ['22-billing', 'Mail · Billing', '/billing'],
    ['23-inbound', 'Mail · Inbound routes', '/inbound'],
    ['24-validation', 'Mail · Validation API', '/validation'],
    ['25-members', 'Mail · Members', '/members'],
    ['26-branding', 'Mail · Branding', '/settings/branding'],
];

test.describe.configure({ mode: 'serial' });
test.setTimeout(120_000);

test.beforeAll(async ({ browser }) => {
    mkdirSync(OUT, { recursive: true });

    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    // Idempotens demó-seedek: napló + domain, Studio, analitika.
    for (const seed of ['/dev/seed-logs', '/dev/seed-studio', '/dev/seed-analytics']) await page.goto(seed);

    await context.close();
});

/** Nyugalmi állapot: hálózat, betűk, animációk vége. */
async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.evaluate(() => document.fonts.ready);
    await page.addStyleTag({ content: '*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }' });
}

for (const [file, frame, route] of SCREENS) {
    test(`${file} — ${frame}`, async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });

        if (route === 'composer') {
            await page.goto('/campaigns');
            const first = page.locator('a[href^="/campaigns/"]').first();
            await expect(first).toBeVisible();
            await first.click();
            await expect(page).toHaveURL(/\/campaigns\/[0-9a-f-]{36}$/);
        } else {
            const response = await page.goto(route);
            expect(response?.status(), route).toBe(200);
        }

        await settle(page);
        await page.screenshot({ path: join(OUT, `${file}.png`) });
    });
}
