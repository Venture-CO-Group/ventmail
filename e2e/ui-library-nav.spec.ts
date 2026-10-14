import { expect, test, type Locator } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 3. pont — aktív menüpont (@venture/ui 0.3.0 VtNavItem): accent szöveg + bal
 * jelölő-csík, HÁTTÉRKITÖLTÉS NÉLKÜL — desktopon a nav-csoport panelén és a mobil harmonikán.
 */

async function activeNavStyle(item: Locator): Promise<{ bg: string; indicator: string; color: string }> {
    return item.evaluate((node) => {
        const style = getComputedStyle(node);
        const before = getComputedStyle(node, '::before');

        return { bg: style.backgroundColor, indicator: `${before.content}|${before.width}|${before.backgroundColor}`, color: style.color };
    });
}

const transparent = (value: string): boolean => value === 'transparent' || /rgba\(\s*0,\s*0,\s*0,\s*0\s*\)/.test(value);

test.describe('3. aktív menüpont', () => {
    test('desktop: a nyitott csoport aktív eleme accent szöveg + bal jelölő, háttérkitöltés nélkül', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/domains');
        await page.getByTestId('nav-group-platform').click();

        const active = page.getByTestId('nav-domains');

        await expect(active).toBeVisible();
        await expect(active).toHaveAttribute('aria-current', 'page');
        await page.mouse.move(0, 0);

        const style = await activeNavStyle(active);

        expect(transparent(style.bg), `háttér: ${style.bg}`).toBe(true);
        // A jelölő-csík: a ::before tartalma nem `none`, szélessége > 0, a háttere nem átlátszó.
        const [content, width, color] = style.indicator.split('|');

        expect(content).not.toBe('none');
        expect(parseFloat(width)).toBeGreaterThan(0);
        expect(transparent(color)).toBe(false);

        // Egy NEM aktív elem nem kap jelölőt.
        const other = page.getByTestId('nav-logs');
        const otherStyle = await activeNavStyle(other);

        expect(otherStyle.color).not.toBe(style.color);
    });

    test('mobil harmonika: ugyanaz az aktív állapot', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/domains');
        await page.getByTestId('nav-hamburger').click();

        const group = page.getByTestId('nav-mobile').getByTestId('nav-group-platform');

        if ((await group.getAttribute('aria-expanded')) !== 'true') await group.click();

        const active = page.getByTestId('nav-mobile').getByTestId('nav-domains');

        await expect(active).toHaveAttribute('aria-current', 'page');

        const style = await activeNavStyle(active);

        expect(transparent(style.bg), `háttér: ${style.bg}`).toBe(true);
        expect(style.indicator.split('|')[0]).not.toBe('none');
    });
});

