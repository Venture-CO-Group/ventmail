import type { ChildProcess } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { startImportWorker } from './support/import-fixtures';

/*
 * UI-POLÍROZÁS · 5. pont — fájlfeltöltés (@venture/ui 0.3.0 VtFileUpload) az importban.
 *
 * A kiválasztott fájl neve a gombbal EGY blokkban, középre igazítva látszik; rossz kiterjesztés
 * hibát ad (és nem indul feltöltés); a mező a VtField címkéjét viseli.
 */
test.describe.configure({ mode: 'serial' });

// A felmérés háttér-sorban fut (D71): a saját queue-workerrel a köteg nem ragad „Elemzés…" állapotban.
let worker: ChildProcess | null = null;

test.beforeAll(() => {
    worker = startImportWorker();
});

test.afterAll(() => {
    worker?.kill();
});

async function openCsvTab(page: import('@playwright/test').Page): Promise<void> {
    await page.goto('/audience/import');

    // Egy félbehagyott korábbi köteg a választó helyett a fájlkártyát mutatná: azt elvetjük.
    const replace = page.getByTestId('import-replace');

    if (await replace.isVisible().catch(() => false)) await replace.click();

    await page.getByRole('tab').nth(2).click();
    await expect(page.getByTestId('import-upload')).toBeVisible();
}

test('rossz kiterjesztés: hibaüzenet, feltöltés nem indul', async ({ page }) => {
    await openCsvTab(page);

    let posted = false;

    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/audience/import') posted = true;
    });

    const upload = page.getByTestId('import-upload');

    await upload.locator('input[type="file"]').setInputFiles({ name: 'kontaktok.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') });

    await expect(upload.getByRole('alert')).toBeVisible();
    await expect(upload.getByRole('alert')).toContainText(/CSV/);
    await page.waitForTimeout(300);
    expect(posted).toBe(false);

    // Az üres állapot szövege marad, a gomb és a szöveg egy sorban, a blokk középre igazítva.
    const geometry = await upload.evaluate((node) => {
        const button = node.querySelector('.vt-upload__trigger')!.getBoundingClientRect();
        const name = node.querySelector('.vt-upload__name')!.getBoundingClientRect();
        const box = node.getBoundingClientRect();
        const control = node.querySelector('.vt-upload__control')!.getBoundingClientRect();

        return {
            sameLine: Math.abs(button.top + button.height / 2 - (name.top + name.height / 2)),
            centred: Math.abs(control.left - box.left - (box.right - control.right)),
        };
    });

    expect(geometry.sameLine).toBeLessThanOrEqual(1);
    expect(geometry.centred).toBeLessThanOrEqual(2);
});

test('helyes CSV: a kiválasztott fájl neve megjelenik, és az import elindul', async ({ page }) => {
    await openCsvTab(page);

    // A feltöltést kicsit késleltetjük, hogy a VÁLASZTÁS állapota (a fájlnév) mérhető legyen.
    await page.route('**/audience/import', async (route) => {
        if (route.request().method() === 'POST') await new Promise((resolve) => setTimeout(resolve, 800));
        await route.continue();
    });

    const name = `ui-polish-${Date.now()}.csv`;
    const upload = page.getByTestId('import-upload');

    await upload.locator('input[type="file"]').setInputFiles({ name, mimeType: 'text/csv', buffer: Buffer.from('email\nuipolish@pelda.hu\n') });

    await expect(upload.locator('.vt-upload__name')).toHaveText(name);

    // A szerver megkapta: a felmérés után az oszlopok lépés jön.
    await expect(page.getByTestId('import-step-columns')).toBeVisible({ timeout: 60_000 });

    // Takarítás: a még nem futtatott köteg törlése (a többi spec üres varázslót vár).
    await page.getByTestId('import-exit').click();
    await page.goto('/audience/import');
    await expect(page.getByTestId('import-step-upload')).toBeVisible();
});

async function pngBytes(page: import('@playwright/test').Page, side: number): Promise<Buffer> {
    const base64 = await page.evaluate((size) => {
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const context = canvas.getContext('2d') as CanvasRenderingContext2D;
        context.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--vt-color-accent-500');
        context.fillRect(0, 0, size, size);

        return canvas.toDataURL('image/png').split(',')[1];
    }, side);

    return Buffer.from(base64, 'base64');
}

test('szerkesztő képválasztó: PNG feltöltés kitölti a kép URL-jét; rossz típus hibát ad, kérés nélkül', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/templates');
    await page.getByTestId('starter-newsletter').click();
    await page.getByTestId('template-name').fill(`E2E képfeltöltés ${Date.now().toString(36)}`);
    await page.getByTestId('template-submit').click();
    await page.waitForURL(/\/templates\/[0-9a-f-]{36}$/);

    await page.locator('[data-testid="editor-canvas"] [data-type="image"]').first().click();
    await expect(page.getByTestId('editor-props')).toBeVisible();

    const upload = page.getByTestId('prop-src-upload');
    const url = page.getByTestId('prop-src-url');
    const before = await url.inputValue();

    let posted = 0;

    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/template-assets') posted++;
    });

    // Rossz típus: a kiválasztáskor elutasítva, kérés nincs.
    await upload.locator('input[type="file"]').setInputFiles({ name: 'kep.txt', mimeType: 'text/plain', buffer: Buffer.from('nem kép') });
    await expect(upload.getByRole('alert')).toBeVisible();
    await page.waitForTimeout(300);
    expect(posted).toBe(0);

    // PNG: feltöltés az asset-tárba, az URL a feltöltött képre áll.
    const [response] = await Promise.all([
        page.waitForResponse((r) => new URL(r.url()).pathname === '/template-assets' && r.request().method() === 'POST'),
        upload.locator('input[type="file"]').setInputFiles({ name: 'kep.png', mimeType: 'image/png', buffer: await pngBytes(page, 120) }),
    ]);

    expect(response.ok()).toBe(true);
    const asset = (await response.json()) as { url: string };

    await expect(url).toHaveValue(asset.url);
    expect(asset.url).not.toBe(before);
    // Siker után a mező üres (v-model null).
    await expect(upload.locator('.vt-upload__name')).not.toHaveText('kep.png');
});

test('branding: feltöltés közben látható folyamatjelző', async ({ page }) => {
    await page.goto('/settings/branding');

    await page.route('**/settings/branding/logo', async (route) => {
        if (route.request().method() === 'POST') await new Promise((resolve) => setTimeout(resolve, 1000));
        await route.continue();
    });

    await page.getByTestId('branding-upload').locator('input[type="file"]').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: await pngBytes(page, 80) });
    await expect(page.getByTestId('branding-uploading')).toBeVisible();
    await expect(page.getByTestId('branding-logo')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('branding-uploading')).toHaveCount(0);

    // Takarítás: a logó eltávolítása (a többi spec alapállapotot vár).
    await page.getByTestId('branding-remove').click();
    await expect(page.getByTestId('branding-logo')).toHaveCount(0);
});
