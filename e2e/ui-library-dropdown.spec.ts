import { expect, test } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 8. pont — legördülők (@venture-co-group/ui VtDropdown): ütközés-figyelés (a lap
 * aljához közeli menü felfelé nyílik, sosem vágja le a nézet), egyszerre egy nyitott, Esc zár.
 * Unified UI v2: a nav-csoportok a sidebarban élnek (nem legördülők) — a héj menüi a
 * workspace-váltó (sidebar), a nyelv és a fiók (felső sáv).
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

    test('a héj menüi közül egyszerre egy nyitott: workspace → nyelv → fiók', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/dashboard');

        await page.getByTestId('workspace-switch').click();
        await expect(page.getByTestId('workspace-list')).toBeVisible();

        await page.getByTestId('locale-switch').click();
        await expect(page.getByTestId('locale-option-hu')).toBeVisible();
        await expect(page.getByTestId('workspace-list')).toBeHidden();

        await page.getByTestId('user-menu').click();
        await expect(page.getByTestId('user-profile')).toBeVisible();
        await expect(page.getByTestId('locale-option-hu')).toBeHidden();

        await page.keyboard.press('Escape');
        await expect(page.getByTestId('user-profile')).toBeHidden();
        await expect(page.getByTestId('user-menu')).toBeFocused();
    });
});
