import { expect, test as setup } from '@playwright/test';

/**
 * EGYETLEN BEJELENTKEZÉS az egész böngésző-suite-ra.
 *
 * Miért: a dev-belépő a ventSSO-folyamat helyettesítője, és a modul minden route-ja a
 * `throttle:oidc` limiter mögött van (20/perc/peer) — ez a BRUTE-FORCE elleni védelem, és
 * jól van úgy. Ha viszont minden teszt külön bejelentkezik, a suite MAGA lépi túl a
 * keretet: mérve a párhuzamos futásban a képernyők 429-cel jöttek vissza, és a hibák
 * ott jelentek meg, ahol nincsenek.
 *
 * A megoldás nem a limit fellazítása, hanem hogy a suite úgy viselkedjen, mint egy
 * felhasználó: egyszer belép, aztán dolgozik.
 */
const STATE = 'e2e/.auth/state.json';

setup('bejelentkezés', async ({ page }) => {
    await page.goto('/auth/dev-login');

    // A belépés UTÁN a héjnak látszania kell — enélkül a mentett állapot egy kilépett
    // munkamenetet rögzítene, és minden teszt átirányításon bukna el.
    await page.goto('/dashboard');
    await expect(page.getByTestId('app-nav')).toBeVisible();

    await page.context().storageState({ path: STATE });
});
