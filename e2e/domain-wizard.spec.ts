import { expect, test } from '@playwright/test';

// A wizard hitelesítést és workspace-kontextust igényel (a modul-route-ok kötelező stackje).
// A ventSSO OIDC-folyamatát a böngésző-teszt nem tudja végigjátszani, ezért a dev-only
// belépési úton megyünk be — az élesben nem is létezik.
/*
 * M0-4 elfogadási kritérium #28 — a wizard flow.
 * A design-tokenek meglétét is ellenőrizzük: a képernyő nem tartalmazhat beégetett értéket.
 */

test('a domain-wizard megjeleníti a hitelesítendő DNS-rekordokat', async ({ page }) => {
    await page.goto('/domains/wizard');

    await expect(page.getByRole('heading', { name: 'Küldő domain hitelesítése' })).toBeVisible();
    await expect(page.getByTestId('wizard-steps')).toBeVisible();

    // 1. lépés: a domain megadása
    await page.getByTestId('domain-input').fill('pelda.hu');
    await expect(page.getByTestId('domain-submit')).toBeEnabled();
});

test('a hitelesítendő rekordok táblázata a @venture/ui táblát használja', async ({ page }) => {
    await page.goto('/domains/wizard?demo=1');

    const table = page.locator('.vt-table');
    await expect(table).toBeVisible();

    /*
     * MIND A KILENC rekord látszik (deploy-ovh óta a két poolonkénti Return-Path is). A darabszám nem formalitás: ha egy rekord csendben
     * kiesik a generátorból, az ügyfél olyan zónát állít be, amivel a domain SOSEM lesz
     * `verified` — és ezt a képernyőn kell észrevenni, nem egy support-ticketből.
     *
     * A lista az A4 (inbound MX) és az A6 (MTA-STS, TLS-RPT) óta hét elemű; ez a teszt
     * négyet várt, és mivel a CI-ban a Playwright-kapu a hiányzó privát csomagok miatt
     * SOHA nem futott le, a hiba fél éve csendben állt itt.
     */
    await expect(page.locator('.vt-table__row')).toHaveCount(9);

    // KÜLDÉS: a hitelesítés három rekordja + a saját domainen futó tracking.
    await expect(page.getByText('v=spf1', { exact: false })).toBeVisible();
    await expect(page.getByText('v=DKIM1', { exact: false })).toBeVisible();
    await expect(page.getByText('v=DMARC1', { exact: false })).toBeVisible();
    await expect(page.getByText('CNAME', { exact: false }).first()).toBeVisible();

    // TLS-HÁZIREND (A6 · 48. kritérium): az egyik előír, a másik megmutatja, betartják-e.
    await expect(page.getByText('v=STSv1', { exact: false })).toBeVisible();
    await expect(page.getByText('v=TLSRPTv1', { exact: false })).toBeVisible();

    // BEJÖVŐ (A4): opcionális, de a wizardnak MEG KELL mutatnia — enélkül a tenant nem
    // tudja, mit kell beállítania a fogadáshoz.
    await expect(page.getByText('MX', { exact: true }).first()).toBeVisible();
});

test('a wizard design-tokeneket használ, nem beégetett értékeket', async ({ page }) => {
    await page.goto('/domains/wizard');

    const usesTokens = await page.evaluate(() =>
        Array.from(document.styleSheets)
            .flatMap((sheet) => {
                try {
                    return Array.from(sheet.cssRules);
                } catch {
                    return [];
                }
            })
            .some((rule) => rule.cssText.includes('--vt-')),
    );

    expect(usesTokens).toBe(true);
});
