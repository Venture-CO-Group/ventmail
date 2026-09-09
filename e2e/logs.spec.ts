import { expect, test } from '@playwright/test';

/*
 * A3 · 29. kritérium — a napló-, trace- és statisztika-flow a böngészőben.
 *
 * A képernyők hitelesítést és workspace-kontextust igényelnek (a modul-route-ok kötelező
 * stackje). A ventSSO OIDC-folyamatát a böngésző-teszt nem tudja végigjátszani, ezért a
 * dev-only belépési úton megyünk be — az élesben nem is létezik.
 *
 * A DEMÓ-ADATOT is a teszt hozza létre. A korábbi változat üres naplón futott, és ha nem
 * talált sort, kilépett — „zölden": egy teszt, ami a hiányzó adatra magától átugrik, nem a
 * képernyőt méri, hanem a saját türelmét.
 */
test.beforeEach(async ({ page }) => {
    // A bejelentkezés a `setup` projektből öröklődik; itt már csak a demó-adat kell.
    await page.goto('/dev/seed-logs');
});

test('a napló szűrhető, és a szűrés a SZERVEREN történik', async ({ page }) => {
    await page.goto('/logs');

    await expect(page.getByRole('heading', { name: 'Napló' })).toBeVisible();

    const rows = page.getByTestId('log-table').locator('tbody tr');

    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('demo@example.test');

    // Nem létező címzettre a SZERVER ad üres listát — nem a kliens rejti el a sort.
    await page.getByTestId('filter-recipient').fill('nincs-ilyen@example.test');
    await page.getByTestId('log-search').click();

    await expect(page).toHaveURL(/recipient=nincs-ilyen/);
    await expect(rows).toHaveCount(0);

    // …és visszaszűrve megint ott van.
    await page.getByTestId('filter-recipient').fill('demo@example.test');
    await page.getByTestId('log-search').click();

    await expect(rows).toHaveCount(1);
});

test('a sorból megnyílik a trace, és a TELJES lánc látszik', async ({ page }) => {
    await page.goto('/logs');

    await page.getByTestId('log-trace').first().click();

    await expect(page.getByRole('heading', { name: 'Üzenet-trace' })).toBeVisible();

    // A lánc minden szeme: gate-döntés → SMTP-párbeszéd → kísérletek → események.
    await expect(page.getByTestId('trace-summary')).toContainText('demo@example.test');
    await expect(page.getByTestId('trace-gates')).toContainText('suppression');
    await expect(page.getByTestId('trace-smtp')).toContainText('250');
    await expect(page.getByTestId('trace-events')).toContainText('delivered');
    await expect(page.getByTestId('trace-events')).toContainText('opened');
    await expect(page.getByTestId('trace-events')).toContainText('clicked');
});

test('a tag-statisztika megmutatja a per-tag arányokat', async ({ page }) => {
    await page.goto('/logs/statistics');

    await expect(page.getByRole('heading', { name: 'Tag-statisztika' })).toBeVisible();
    await expect(page.getByTestId('stats-table')).toContainText('demo');

    // A nyitási arány torzításáról a képernyőn kell szólni, nem a dokumentációban.
    await expect(page.getByTestId('stats-mpp')).toBeVisible();

    await page.getByTestId('stats-7').click();

    await expect(page).toHaveURL(/days=7/);
    await expect(page.getByTestId('stats-range')).toContainText('7 nap');
});
