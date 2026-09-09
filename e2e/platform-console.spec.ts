import { expect, test } from '@playwright/test';

/*
 * M4-5 · A PLATFORM-KONZOL a böngészőben.
 *
 * A dev-belépő NEM platform-admin (a jogot a ventSSO külön claimje adja), ezért a suite
 * itt azt méri, ami e nélkül is bizonyítható és fontos: hogy a konzol NEM elérhető
 * tenant-jogosultsággal, és hogy a menüben sem jelenik meg. A platform-admin oldali
 * viselkedést a Pest-tesztek fedik (ott a claim beállítható).
 */
test('a platform-konzol tenant-jogosultsággal NEM elérhető', async ({ page }) => {
    const response = await page.goto('/platform');

    expect(response?.status()).toBe(403);
});

test('a konzol menüpontja nem is látszik a tenant-felhasználónak', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByTestId('nav-console')).toHaveCount(0);
    // A `nav-platform` a TENANT platform-csoportja (domainek, kulcsok…) — az látszik. A
    // konzolé külön testid, épp hogy a kettő ne mosódjon össze.
    await expect(page.getByTestId('nav-platform-console')).toHaveCount(0);
    await expect(page.getByTestId('nav-platform-audit')).toHaveCount(0);
});
