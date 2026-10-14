import { expect, test } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 8. pont — legördülők (@venture/ui 0.3.0 VtDropdown): ütközés-figyelés (a lap
 * aljához közeli menü felfelé nyílik, sosem vágja le a nézet), egyszerre egy nyitott, Esc zár.
 */

test.describe('8. legördülők', () => {
    test('a lap aljához közeli legördülő FELFELÉ nyílik, és teljesen látható marad', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/analytics');
        await page.waitForLoadState('networkidle').catch(() => undefined);

        const trigger = page.locator('[data-testid^="export-"][aria-haspopup="menu"]').last();

        await trigger.evaluate((node) => node.scrollIntoView({ block: 'end' }));
        await trigger.click();

        const panelId = await trigger.getAttribute('aria-controls');
        const panel = page.locator(`[id="${panelId}"]`);

        await expect(panel).toBeVisible();

        const box = (await panel.boundingBox())!;
        const triggerBox = (await trigger.boundingBox())!;
        const viewport = page.viewportSize()!;

        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
        // A trigger a nézet alján: a panel FÖLÉ került.
        expect(box.y + box.height).toBeLessThanOrEqual(triggerBox.y + 1);

        // Esc bezárja, a fókusz a triggerre tér vissza.
        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test('a nav-csoportok: egyszerre egy nyitott, Esc zár, a panel nem lóg ki a nézetből', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/dashboard');

        await page.getByTestId('nav-group-studio').click();
        await expect(page.getByTestId('nav-campaigns')).toBeVisible();

        await page.getByTestId('nav-group-platform').click();
        await expect(page.getByTestId('nav-domains')).toBeVisible();
        await expect(page.getByTestId('nav-campaigns')).toBeHidden();

        const panel = page.locator(`[id="${await page.getByTestId('nav-group-platform').getAttribute('aria-controls')}"]`);
        const box = (await panel.boundingBox())!;

        expect(box.y + box.height).toBeLessThanOrEqual(800);

        await page.keyboard.press('Escape');
        await expect(page.getByTestId('nav-domains')).toBeHidden();
    });
});
