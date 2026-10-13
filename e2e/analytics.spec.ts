import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';

/*
 * v1.1 · 8. modul — ANALITIKA a böngészőben: a kártyák kirajzolódnak, a PNG-export (2×,
 * átlátszó / sötét háttér) a menüből BILLENTYŰZETTEL is elérhető, a PDF-riport háttérfeladatként
 * elkészül és letölthető.
 *
 * A demó-adatot a `/dev/seed-analytics` hozza (idempotens). A PDF a `fake` rendererrel készül
 * (`ANALYTICS_PDF_RENDERER=fake`, a sor szinkron) — a valódi Gotenberget a
 * `@group gotenberg` kontraktus-teszt méri.
 */
test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
    await page.goto('/dev/seed-analytics');
});

/*
 | A DEMÓ-kampányt választjuk ki EXPLICITEN: alapból a legutóbb kiküldött kampány nyílik, és a
 | `studio-campaign.spec.ts` egy korábbi futása (ugyanabban a dev-adatbázisban) egy esemény
 | nélküli „E2E checklist" kampányt hagy hátra — a második teljes futáson az lett az alapértelmezett,
 | és a kampány-kártyák üres állapotban jelentek meg (mérve).
 */
async function openAnalytics(page: Page): Promise<void> {
    await page.goto('/analytics?range=90');
    const demo = await page
        .getByTestId('analytics-campaign')
        .locator('option', { hasText: 'Őszi újdonságok – hírlevél a teljes törzsvendégkörnek (demó' })
        .first()
        .getAttribute('value');
    expect(demo).toBeTruthy();
    await page.goto(`/analytics?range=90&campaign=${demo}`);
    await expect(page.getByTestId('chart-open-click')).toBeVisible();
}

/** PNG fejléc + IHDR szélesség. */
function pngWidth(bytes: Buffer): number {
    expect([...bytes.subarray(0, 8)]).toEqual([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

    return bytes.readUInt32BE(16);
}

test('a kártyák kirajzolódnak: idősor, eszköz, top linkek, növekedés, journey, kézbesíthetőség, bounce', async ({ page }) => {
    await openAnalytics(page);

    for (const id of ['chart-open-click', 'chart-devices', 'top-links', 'chart-growth', 'chart-engagement', 'chart-journey', 'chart-deliverability', 'chart-bounces']) {
        await expect(page.getByTestId(id)).toBeVisible();
    }

    await expect(page.getByTestId('client-list')).toContainText('Apple MPP');
    await expect(page.getByTestId('heatmap-frame')).toBeVisible();
    await expect(page.getByTestId('heatmap-pct').first()).toBeVisible();

    // A hőtérkép sandboxolt, szkript nélküli iframe-ben van.
    await expect(page.getByTestId('heatmap-frame')).toHaveAttribute('sandbox', 'allow-same-origin');
});

test('PNG-export billentyűzettel: átlátszó, 2× szélesség, átlátszó sarok-pixel', async ({ page }) => {
    await openAnalytics(page);

    const canvasWidth = await page.getByTestId('chart-open-click').locator('canvas').first().evaluate((canvas: HTMLCanvasElement) => canvas.getBoundingClientRect().width);

    const trigger = page.getByTestId('export-open-click');
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menu')).toBeVisible();
    await expect(page.getByTestId('export-open-click-png-transparent')).toBeFocused();

    const [download] = await Promise.all([page.waitForEvent('download'), page.keyboard.press('Enter')]);

    expect(download.suggestedFilename()).toMatch(/^megnyitas-es-kattintas-.+-\d{8}\.png$/);
    const bytes = await readFile((await download.path()) as string);

    expect(pngWidth(bytes)).toBe(Math.round(canvasWidth) * 2);

    // A bal felső sarok-pixel a böngészőben dekódolva átlátszó.
    const alpha = await page.evaluate(async (base64) => {
        const image = new Image();
        image.src = `data:image/png;base64,${base64}`;
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = image.width;
        canvas.height = image.height;
        const context = canvas.getContext('2d') as CanvasRenderingContext2D;
        context.drawImage(image, 0, 0);

        return context.getImageData(0, 0, 1, 1).data[3];
    }, bytes.toString('base64'));

    expect(alpha).toBe(0);

    // A menü Esc-re zár, a fókusz visszatér a gombra.
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menu')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(trigger).toBeFocused();
});

test('PNG-export sötét háttérrel: a sarok-pixel átlátszatlan', async ({ page }) => {
    await openAnalytics(page);

    await page.getByTestId('export-growth').click();
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-growth-png-dark').click()]);
    const bytes = await readFile((await download.path()) as string);

    pngWidth(bytes);

    const alpha = await page.evaluate(async (base64) => {
        const image = new Image();
        image.src = `data:image/png;base64,${base64}`;
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = image.width;
        canvas.height = image.height;
        const context = canvas.getContext('2d') as CanvasRenderingContext2D;
        context.drawImage(image, 0, 0);

        return context.getImageData(0, 0, 1, 1).data[3];
    }, bytes.toString('base64'));

    expect(alpha).toBe(255);
});

test('a hőtérkép-kártya exportja a top linkek CSV-je', async ({ page }) => {
    await openAnalytics(page);

    await page.getByTestId('export-heatmap').click();
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-heatmap-csv').click()]);
    const csv = await readFile((await download.path()) as string, 'utf8');

    expect(csv).toContain('nador-kavehaz.example/foglalas');
});

test('PDF-riport: kérés → kész → letöltés (%PDF-)', async ({ page }) => {
    await openAnalytics(page);

    const panel = page.getByTestId('report-panel');
    await panel.scrollIntoViewIfNeeded();
    await page.getByTestId('report-create').click();

    await expect(page.getByTestId('report-download')).toBeVisible({ timeout: 30_000 });

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('report-download').click()]);
    const pdf = await readFile((await download.path()) as string);

    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-');
    expect(download.suggestedFilename()).toMatch(/\.pdf$/);
});
