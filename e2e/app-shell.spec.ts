import { expect, test } from '@playwright/test';

/*
 * M4-0 · AZ APP-HÉJ a böngészőben.
 *
 * A héj minden későbbi képernyő alapja: ha a menü, a workspace-váltó vagy a nyelvváltó
 * elromlik, az MINDEN oldalon elromlik. Ezért van rá saját spec, és ezért méri a
 * NYELVVÁLTÁST is — a hat nyelv addig díszlet, amíg a felületen nem lehet váltani köztük.
 */
/*
 | A NYELV a felhasználó SORÁN él, tehát átnyúlik a teszteken (a suite egyetlen
 | munkamenetet használ). Ebből két dolog következik:
 |
 | 1. A fájl tesztjei SOROSAN futnak. Párhuzamosan az egyik teszt `afterEach`-e
 |    visszaállítaná a nyelvet, miközben a másik épp a váltást méri — mérve: a német
 |    felirat ellenőrzése véletlenszerűen bukott el, mert közben egy másik worker
 |    magyarra állította a fiókot.
 | 2. Minden teszt UTÁN visszaállítunk: egy elbukó futás különben németre állítva hagyná a
 |    fiókot, és a KÖVETKEZŐ futás első tesztje bukna el — ott, ahol nincs is hiba.
 */
test.describe.configure({ mode: 'serial' });

test.afterEach(async ({ page }) => {
    await page.goto('/dashboard');
    await page.getByTestId('locale-switch').locator('select').selectOption('hu');
});

test('a nyitóképernyő a KÜLDÉST MEGÁLLÍTÓ számokat mutatja', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByTestId('dashboard-metrics')).toBeVisible();
    await expect(page.getByTestId('metric-quota')).toBeVisible();
    await expect(page.getByTestId('metric-domains')).toBeVisible();
    await expect(page.getByTestId('metric-suppressions')).toBeVisible();

    // A menü a KÉPESSÉGEKET követi: a dev-belépő `owner`, tehát látja a platform-csoportot.
    // A `testid` szándékos: a feliratok nyelvfüggők, a menü megléte nem.
    await expect(page.getByTestId('nav-platform')).toBeVisible();
    await expect(page.getByTestId('nav-logs')).toBeVisible();
    await expect(page.getByTestId('nav-domains')).toBeVisible();
});

test('a NYELVVÁLTÁS a szerveren dől el, és a felület követi', async ({ page }) => {
    await page.goto('/dashboard');

    /*
     | ISMERT KIINDULÁS. A nyelv a felhasználó SORÁN él (nem session-ben), tehát átnyúlik a
     | tesztek között: ha egy korábbi futás németre váltott, egy „alapból magyar"
     | feltételezés hamis lenne. A teszt ezért maga állítja be a kiindulást.
     */
    await page.getByTestId('locale-switch').locator('select').selectOption('hu');
    await expect(page.getByRole('heading', { name: 'Áttekintés' })).toBeVisible();

    // A `data-testid` a VtSelect GYÖKÉR elemén ül (a komponens úgy adja tovább), a
    // választás viszont a belső `select`-en történik.
    await page.getByTestId('locale-switch').locator('select').selectOption('de');

    // A váltás POST-ol, a szerver írja a felhasználó sorát, és az új oldal MÁR németül jön.
    await expect(page.getByRole('heading', { name: 'Übersicht' })).toBeVisible();
    await expect(page.getByTestId('flash-success')).toBeVisible();

    // …és megmarad: egy teljes újratöltés után is német.
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Übersicht' })).toBeVisible();

    // Vissza magyarra, hogy egy elbukó futás se hagyjon idegen nyelvű állapotot a
    // következő tesztnek (a `finally` a lényeg: bukáskor is lefut).
    await page.getByTestId('locale-switch').locator('select').selectOption('hu');
    await expect(page.getByRole('heading', { name: 'Áttekintés' })).toBeVisible();
});

test('a workspace-váltó CSAK a saját tagságokat kínálja', async ({ page }) => {
    await page.goto('/dashboard');

    const options = page.getByTestId('workspace-switch').locator('select option');

    await expect(options).toHaveCount(1);
    await expect(options.first()).toHaveText('Dev workspace');
});
