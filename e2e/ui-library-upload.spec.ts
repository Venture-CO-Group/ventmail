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
