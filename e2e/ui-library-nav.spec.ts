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

        // Egy NEM aktív elem nem kap jelölőt: a ::before nincs vagy átlátszó, a szín más.
        const other = page.getByTestId('nav-logs');
        const otherStyle = await activeNavStyle(other);
        const [otherContent, , otherColor] = otherStyle.indicator.split('|');

        expect(otherContent === 'none' || transparent(otherColor), `inaktív jelölő: ${otherStyle.indicator}`).toBe(true);
        expect(otherStyle.color).not.toBe(style.color);
        expect(transparent(otherStyle.bg)).toBe(true);
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


test('workspace-váltó: a jelenlegi workspace sora háttérkitöltés nélkül, accent szöveggel és pipával', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard');
    await page.getByTestId('workspace-switch').click();

    const list = page.getByTestId('workspace-list');
    const current = list.locator('button[aria-current="true"]');

    await expect(current).toHaveCount(1);
    await expect(current.getByTestId('workspace-current-check')).toBeVisible();
    await expect(list.getByTestId('workspace-current-check')).toHaveCount(1);
    await page.mouse.move(0, 0);

    const style = await current.evaluate((node) => ({ bg: getComputedStyle(node).backgroundColor, color: getComputedStyle(node).color }));

    expect(transparent(style.bg), `háttér: ${style.bg}`).toBe(true);

    // A keresés és a lista működése változatlan.
    await page.getByTestId('workspace-search').fill('nincs-ilyen-workspace');
    await expect(list.locator('button')).toHaveCount(0);
    await page.getByTestId('workspace-search').fill('');
    await expect(current).toBeVisible();
});

test('felső sáv (D319): az aktív elem háttérkitöltés nélkül, jelölő ALATTA; az inaktívnak nincs jelölője', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard');
    await page.mouse.move(0, 0);

    const overview = page.getByTestId('nav-overview');

    await expect(overview).toHaveAttribute('aria-current', 'page');

    const active = await overview.evaluate((node) => {
        const style = getComputedStyle(node);
        const before = getComputedStyle(node, '::before');
        const rect = node.getBoundingClientRect();

        return {
            bg: style.backgroundColor,
            content: before.content,
            color: before.backgroundColor,
            height: parseFloat(before.height),
            width: parseFloat(before.width),
            bottomGap: rect.bottom - (rect.top + parseFloat(before.top) + parseFloat(before.height)),
        };
    });

    expect(transparent(active.bg), `háttér: ${active.bg}`).toBe(true);
    expect(active.content).not.toBe('none');
    expect(transparent(active.color)).toBe(false);
    // Vízszintes csík a doboz ALJÁN (szélesebb, mint magas; az alsó élhez tapad).
    expect(active.width).toBeGreaterThan(active.height);
    expect(Math.abs(active.bottomGap)).toBeLessThanOrEqual(1);

    // Inaktív sáv-elem (egy csoport, amelyben nincs az aktuális oldal): nincs jelölő, nincs kitöltés.
    const studio = page.getByTestId('nav-group-studio');

    await expect(studio).not.toHaveAttribute('aria-current', 'page');
    const inactive = await studio.evaluate((node) => ({
        bg: getComputedStyle(node).backgroundColor,
        content: getComputedStyle(node, '::before').content,
        color: getComputedStyle(node, '::before').backgroundColor,
    }));

    expect(transparent(inactive.bg)).toBe(true);
    expect(inactive.content === 'none' || transparent(inactive.color)).toBe(true);

    // Egy csoporton belüli oldalon a CSOPORT a sáv aktív eleme.
    await page.goto('/domains');
    await page.mouse.move(0, 0);
    await expect(page.getByTestId('nav-group-platform')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('nav-overview')).not.toHaveAttribute('aria-current', 'page');
});
