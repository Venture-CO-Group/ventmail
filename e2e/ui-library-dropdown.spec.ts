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

        const panel = page.getByTestId('nav-panel-platform');
        const box = (await panel.boundingBox())!;

        expect(box.y + box.height).toBeLessThanOrEqual(800);

        await page.keyboard.press('Escape');
        await expect(page.getByTestId('nav-domains')).toBeHidden();
    });
});

test('egyszerre egy nyitott — HOVERREL is: A fölé, majd B fölé húzva csak B marad nyitva', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard');

    const studio = page.getByTestId('nav-group-studio');
    const platform = page.getByTestId('nav-group-platform');

    await studio.hover();
    await expect(studio).toHaveAttribute('aria-expanded', 'true');

    await platform.hover();
    await expect(platform).toHaveAttribute('aria-expanded', 'true');
    await expect(studio).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('nav-campaigns')).toBeHidden();
});

test('egyszerre egy nyitott — BILLENTYŰZETTEL: A-t, majd B-t nyitva csak B marad nyitva', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard');

    const studio = page.getByTestId('nav-group-studio');
    const platform = page.getByTestId('nav-group-platform');

    await studio.focus();
    await page.keyboard.press('Enter');
    await expect(studio).toHaveAttribute('aria-expanded', 'true');

    await platform.focus();
    await page.keyboard.press('Enter');
    await expect(platform).toHaveAttribute('aria-expanded', 'true');
    await expect(studio).toHaveAttribute('aria-expanded', 'false');
});

/*
 | D320 (@venture/ui 0.3.1): a Platform-panel széles, KÉThasábos — minden pont látszik, belső
 | görgetés / levágott túlcsordulás nincs, és a panel a nézetben marad. Mobilon harmonika.
 */
for (const [width, height] of [
    [1440, 900],
    [1280, 800],
    [390, 844],
] as const) {
    test(`Platform-panel ${width}×${height}: minden pont látszik, nincs rejtett túlcsordulás, a nézetben marad`, async ({ page }) => {
        await page.setViewportSize({ width, height });
        await page.goto('/dashboard');

        if (width < 600) {
            // Mobil: harmonika (nincs legördülő panel) — a csoport minden pontja a lapon.
            await page.getByTestId('nav-hamburger').click();
            const group = page.getByTestId('nav-mobile').getByTestId('nav-group-platform');

            if ((await group.getAttribute('aria-expanded')) !== 'true') await group.click();
            await expect(page.getByTestId('nav-panel-platform')).toHaveCount(0);

            const items = page.getByTestId('nav-mobile').locator('#nav-mobile-platform a');

            expect(await items.count()).toBeGreaterThan(10);
            for (const item of await items.all()) await expect(item).toBeVisible();

            return;
        }

        await page.getByTestId('nav-group-platform').click();
        const panel = page.getByTestId('nav-panel-platform');

        await expect(panel).toBeVisible();
        await expect(panel).toHaveAttribute('role', 'group');
        await expect(panel).toHaveAccessibleName(/.+/);

        const metrics = await panel.evaluate((node) => {
            const rect = node.getBoundingClientRect();
            const links = Array.from(node.querySelectorAll('a'));
            const lefts = new Set(links.map((link) => Math.round(link.getBoundingClientRect().left)));

            return {
                scroll: node.scrollHeight - node.clientHeight,
                top: rect.top,
                bottom: rect.bottom,
                left: rect.left,
                right: rect.right,
                links: links.length,
                columns: lefts.size,
                hidden: links.filter((link) => {
                    const box = link.getBoundingClientRect();

                    return box.bottom > rect.bottom + 1 || box.top < rect.top - 1;
                }).length,
            };
        });

        expect(metrics.scroll).toBeLessThanOrEqual(1);
        expect(metrics.hidden).toBe(0);
        expect(metrics.columns).toBe(2);
        expect(metrics.links).toBeGreaterThan(10);
        expect(metrics.top).toBeGreaterThanOrEqual(0);
        expect(metrics.bottom).toBeLessThanOrEqual(height);
        expect(metrics.left).toBeGreaterThanOrEqual(0);
        expect(metrics.right).toBeLessThanOrEqual(width);
    });
}
