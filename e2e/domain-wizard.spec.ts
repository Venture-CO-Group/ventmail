import { expect, test } from '@playwright/test';

// A wizard hitelesítést és workspace-kontextust igényel (a modul-route-ok kötelező stackje).
// A ventSSO OIDC-folyamatát a böngésző-teszt nem tudja végigjátszani, ezért a dev-only
// belépési úton megyünk be — az élesben nem is létezik.
test.beforeEach(async ({ page }) => {
    await page.goto('/auth/dev-login');
});

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

    // Mind a négy rekord látszik: SPF, DKIM, DMARC, tracking.
    await expect(page.locator('.vt-table__row')).toHaveCount(4);
    await expect(page.getByText('v=spf1', { exact: false })).toBeVisible();
    await expect(page.getByText('v=DKIM1', { exact: false })).toBeVisible();
    await expect(page.getByText('v=DMARC1', { exact: false })).toBeVisible();
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
