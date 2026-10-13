import { expect, test } from '@playwright/test';

/*
 * v1.1 · SHELL — a KIJELENTKEZÉS (avatar-menü → „Kijelentkezés").
 *
 * A fájl neve szándékosan `zz-`: Playwright betűrendben futtat, és ez a teszt MEGSZÜNTETI a
 * közös dev-munkamenetet — ami után minden további teszt kilépett állapotban indulna. Ezért
 * (1) utolsónak fut, és (2) a végén újra belép, és a friss munkamenetet visszaírja a
 * `storageState` fájlba, így a következő futás is épségben indul.
 *
 * Külön böngésző-környezet nem jó: a dev-belépő UGYANAZT a felhasználót adja, és egy második
 * belépés a mért viselkedés szerint a közös munkamenetet is érvényteleníti.
 */
const STATE = 'e2e/.auth/state.json';

test('a kijelentkezés lezárja a munkamenetet (RP-initiated logout)', async ({ page, baseURL }) => {
    const origin = new URL(baseURL ?? 'http://127.0.0.1:8000').origin;

    // A ventSSO end-session végpontját nem hívjuk meg: a külső navigáció blokkolva.
    await page.route((url) => url.origin !== origin, (route) => route.abort());

    await page.goto('/dashboard');
    await expect(page.getByTestId('app-nav')).toBeVisible();

    const logout = page.waitForResponse(
        (response) => new URL(response.url()).pathname === '/auth/logout' && response.request().method() === 'POST',
    );

    await page.getByTestId('user-menu').click();
    await page.getByTestId('logout').click();
    await logout;

    // A kilépés teljes oldalas navigáció (Inertia location): a ventSSO end-session
    // végpontja, ennek hiányában a nyitóoldal. Megvárjuk, hogy a következő lépés ne ütközzön.
    await page.waitForURL((url) => url.pathname === '/');
    await expect(page.getByTestId('app-nav')).toHaveCount(0);

    // A munkamenet megszűnt: a védett oldal nem szolgálható ki, belépésre irányít.
    const after = await page.context().request.get('/dashboard', { maxRedirects: 0 });

    expect([301, 302, 303]).toContain(after.status());

    // Helyreállítás a következő tesztek / futások számára.
    await page.unroute((url) => url.origin !== origin);
    await page.goto('/auth/dev-login');
    await page.goto('/dashboard');
    await expect(page.getByTestId('app-nav')).toBeVisible();
    await page.context().storageState({ path: STATE });
});
