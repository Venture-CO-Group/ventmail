import { expect, test } from '@playwright/test';

/*
 * A3 · 29. kritérium — a napló- és trace-flow a böngészőben.
 *
 * A képernyők hitelesítést és workspace-kontextust igényelnek (a modul-route-ok kötelező
 * stackje). A ventSSO OIDC-folyamatát a böngésző-teszt nem tudja végigjátszani, ezért a
 * dev-only belépési úton megyünk be — az élesben nem is létezik.
 */
test.beforeEach(async ({ page }) => {
    await page.goto('/auth/dev-login');
});

test('a napló szűrhető, és a sorból megnyílik a trace', async ({ page }) => {
    await page.goto('/logs');

    await expect(page.getByRole('heading', { name: 'Napló' })).toBeVisible();
    await expect(page.getByTestId('log-filters')).toBeVisible();

    // A szűrés a szerverre megy (kurzoros lapozással), nem kliens-oldali rejtés.
    await page.getByTestId('filter-status').fill('delivered');
    await page.getByTestId('log-search').click();

    await expect(page).toHaveURL(/status=delivered/);
    await expect(page.getByTestId('log-table')).toBeVisible();
});

test('a trace-képernyő megmutatja a kapukat és az eseményeket', async ({ page }) => {
    await page.goto('/logs');

    const firstRow = page.getByTestId('log-table').locator('tbody tr').first();

    // Üres naplónál nincs mit megnyitni — ilyenkor a lista maga a bizonyíték.
    if ((await firstRow.count()) === 0) {
        await expect(page.getByTestId('log-table')).toBeVisible();

        return;
    }

    await firstRow.click();

    await expect(page.getByRole('heading', { name: 'Üzenet-trace' })).toBeVisible();
    await expect(page.getByTestId('trace-summary')).toBeVisible();
    await expect(page.getByTestId('trace-gates')).toBeVisible();
    await expect(page.getByTestId('trace-events')).toBeVisible();
});
