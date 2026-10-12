import { expect, test } from '@playwright/test';

// A belépőt VENDÉGKÉNT mérjük: a suite alap-állapota a bejelentkezett munkamenet.
test.use({ storageState: { cookies: [], origins: [] } });

test('a vendég a ventSSO-gombos belépőt látja, @venture/design tokenekkel', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByTestId('auth-shell')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // A belépés TELJES NAVIGÁCIÓ (nem Inertia-link): az /auth/redirect külső origin-re visz.
    const button = page.getByTestId('login-sso');
    await expect(button).toHaveAttribute('href', '/auth/redirect');

    await expect(page.getByTestId('auth-version')).toContainText('v');
    await expect(page.locator('a[href="https://sso.ventureco.app/terms"]')).toBeVisible();
    await expect(page.locator('a[href="https://sso.ventureco.app/privacy-policy"]')).toBeVisible();

    // Sötét téma kényszerítve, és a tokenek CSS-változóként jelen vannak.
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    const hasTokens = await page.evaluate(() =>
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
    expect(hasTokens).toBe(true);
});

test('a bejelentkezett felhasználót a / a dashboardra viszi', async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    await page.goto('/');
    await expect(page).toHaveURL(/\/dashboard$/);

    await context.close();
});
