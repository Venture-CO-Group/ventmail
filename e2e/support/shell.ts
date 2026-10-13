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
export async function openNavGroup(page: Page, group: NavGroup): Promise<void> {
    const trigger = page.getByTestId(`nav-group-${group}`);

    if ((await trigger.getAttribute('aria-expanded')) !== 'true') {
        await trigger.click();
    }

    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
}

/** Kinyitja a csoportját, majd rákattint a menüpontra. */
export async function clickNav(page: Page, group: NavGroup, testid: string): Promise<void> {
    await openNavGroup(page, group);
    await page.getByTestId(testid).click();
}

/**
 * A nyelvet a nyelvválasztón át váltja (a felhasználó útja): desktopon a sáv legördülője,
 * hamburger-nézetben a hamburger-panel gombsora.
 */
export async function chooseLocale(page: Page, code: string): Promise<void> {
    const hamburger = page.getByTestId('nav-hamburger');
    const mobile = (await hamburger.count()) > 0;

    if (mobile) {
        await hamburger.click();
    } else {
        const already = ((await page.getByTestId('locale-current').textContent()) ?? '').trim().toLowerCase() === code;

        await page.getByTestId('locale-switch').click();

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

    await page.getByTestId(`locale-option-${code}`).click();
    await saved.catch(() => undefined); // az aktív nyelv újraválasztása (mobil) nem hív szervert

    if (mobile) {
        await page.getByTestId('nav-hamburger').click();
    }
}
