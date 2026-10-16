import { expect, type Page } from '@playwright/test';

/*
 * A FELSŐ NAVIGÁCIÓ segédei az e2e-specekhez.
 *
 * A menüpontok csoportokban élnek (Studio / Platform / Settings), a panel zárva indul — egy
 * `nav-*` pont előtt ki kell nyitni a csoportját. A `testid`-kre illesztünk, nem feliratra:
 * a felirat nyelvfüggő.
 */
export type NavGroup = 'studio' | 'platform' | 'settings' | 'console';

/** Megnyitja egy nav-csoport panelét (desktop). */
/** A sidebar csak 1024 px alatt fiók: ilyenkor a felső sáv menügombja nyitja. */
export async function openSidebar(page: Page): Promise<void> {
    const menu = page.locator('.vt-topbar__menu');

    if ((await menu.count()) > 0 && (await menu.isVisible()) && (await menu.getAttribute('aria-expanded')) !== 'true') {
        await menu.click();
    }

    await expect(page.getByTestId('app-nav')).toBeVisible();
}

/** Egy navigációs csoport (Studio / Platform / Settings / Console) gombja a sidebarban. */
export function navGroupButton(page: Page, group: NavGroup) {
    return page.getByTestId(`nav-${group}`).locator('button.vt-navgroup__label');
}

export async function openNavGroup(page: Page, group: NavGroup): Promise<void> {
    await openSidebar(page);
    const trigger = navGroupButton(page, group);

    if ((await trigger.getAttribute('aria-expanded')) !== 'true') {
        await trigger.click();
    }

    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
}

export async function clickNav(page: Page, group: NavGroup, testid: string): Promise<void> {
    await openNavGroup(page, group);
    await page.getByTestId(testid).click();
}

/*
 | NYELVVÁLTÁS: a felső sáv nyelvi kódja nyit menüt; 520 px alatti sávban a kód rejtett, és a
 | fiókmenü alján lévő gombsor vált.
 */
export async function chooseLocale(page: Page, code: string): Promise<void> {
    const toggle = page.getByTestId('locale-switch');
    const inline = !(await toggle.isVisible().catch(() => false));

    if (inline) {
        await page.getByTestId('user-menu').click();
    } else {
        const already = ((await toggle.textContent()) ?? '').trim().toLowerCase() === code;

        await toggle.click();

        // Az már aktív nyelv választása nem hív szervert (nincs mit menteni): csak zárunk.
        if (already) {
            await page.getByTestId(`locale-option-${code}`).click();

            return;
        }
    }

    // A választás szerver-hívás (`POST /locale`): megvárjuk, különben egy azonnali
    // navigáció megszakítaná, és a nyelv a fiókon a régi maradna.
    const saved = page.waitForResponse(
        (response) => new URL(response.url()).pathname === '/locale' && response.request().method() === 'POST',
        { timeout: 5_000 },
    );

    await page.getByTestId(inline ? `locale-inline-${code}` : `locale-option-${code}`).click();
    await saved.catch(() => undefined); // az aktív nyelv újraválasztása nem hív szervert
}
