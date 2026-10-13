import { expect, test } from '@playwright/test';
import { openNavGroup } from './support/shell';

/*
 * v1.1 · 8. modul — WORKSPACE-BRANDING: Settings ▾ Branding, élő kontraszt-jelzés és A4-borító-
 * előnézet, mentés, logó-feltöltés (PNG) és -eltávolítás; az SVG-t a szerver elutasítja.
 */
test.describe.configure({ mode: 'serial' });

// 1×1-es PNG nem elég (64 px a minimum): egy 80×80-as, egyszínű PNG a böngészőben készül.
async function pngBytes(page: import('@playwright/test').Page, size: number): Promise<Buffer> {
    const base64 = await page.evaluate((side) => {
        const canvas = document.createElement('canvas');
        canvas.width = side;
        canvas.height = side;
        const context = canvas.getContext('2d') as CanvasRenderingContext2D;
        context.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--vt-color-accent-500');
        context.fillRect(0, 0, side, side);

        return canvas.toDataURL('image/png').split(',')[1];
    }, size);

    return Buffer.from(base64, 'base64');
}

test('a Settings menüből elérhető, a színre élő kontraszt-jelzés és előnézet jön', async ({ page }) => {
    await page.goto('/dashboard');
    await openNavGroup(page, 'settings');
    await page.getByTestId('nav-branding').click();
    await expect(page).toHaveURL(/\/settings\/branding$/);

    await page.getByTestId('branding-color').fill('#0E1322');
    await expect(page.getByTestId('branding-contrast')).toContainText(':');
    await expect(page.getByTestId('branding-preview')).toHaveClass(/vm-a4--light/);

    await page.getByTestId('branding-color').fill('#777777');
    await expect(page.getByTestId('branding-preview')).toHaveClass(/vm-a4--dark/);

    await page.getByTestId('branding-company').fill('E2E Kávéház');
    await expect(page.getByTestId('cover-company')).toHaveText('E2E Kávéház');
});

test('mentés: érvényes szín elmentődik, olvashatatlan szín elutasul', async ({ page }) => {
    await page.goto('/settings/branding');

    await page.getByTestId('branding-color').fill('#777777');
    await page.getByTestId('branding-save').click();
    await expect(page.getByTestId('branding-errors')).toBeVisible();

    await page.getByTestId('branding-color').fill('#0E1322');
    await page.getByTestId('branding-footer').fill('E2E lábléc · Bizalmas');
    await page.getByTestId('branding-save').click();
    await expect(page.getByTestId('branding-errors')).toHaveCount(0);

    await page.reload();
    await expect(page.getByTestId('branding-color')).toHaveValue('#0E1322');
    await expect(page.getByTestId('cover-footer')).toHaveText('E2E lábléc · Bizalmas');
});

test('logó: PNG feltöltés és eltávolítás; SVG elutasítva', async ({ page }) => {
    await page.goto('/settings/branding');

    await page.getByTestId('branding-file').setInputFiles({ name: 'logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"/>') });
    await expect(page.getByTestId('branding-errors')).toBeVisible();

    await page.getByTestId('branding-file').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: await pngBytes(page, 80) });
    await expect(page.getByTestId('branding-logo')).toBeVisible();
    await expect(page.getByTestId('branding-remove')).toBeVisible();

    await page.getByTestId('branding-remove').click();
    await expect(page.getByTestId('branding-logo')).toHaveCount(0);
});

test.afterAll(async ({ browser }) => {
    // A közös dev-tenant brandingje alaphelyzetbe (a többi spec a Venture-alapértelmezést várja).
    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();
    await page.goto('/settings/branding');
    await page.getByTestId('branding-color').fill('');
    await page.getByTestId('branding-footer').fill('');
    await page.getByTestId('branding-company').fill('');
    await page.getByTestId('branding-save').click();
    await context.close();
});
