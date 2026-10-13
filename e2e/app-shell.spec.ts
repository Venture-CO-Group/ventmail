import { expect, test } from '@playwright/test';
import { chooseLocale, openNavGroup } from './support/shell';

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
    await chooseLocale(page, 'hu');
});

test('a nyitóképernyő a KÜLDÉST MEGÁLLÍTÓ számokat mutatja', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByTestId('dashboard-metrics')).toBeVisible();
    await expect(page.getByTestId('metric-quota')).toBeVisible();
    await expect(page.getByTestId('metric-domains')).toBeVisible();
    await expect(page.getByTestId('metric-suppressions')).toBeVisible();

    // A menü a KÉPESSÉGEKET követi: a dev-belépő `owner`, tehát látja a platform-csoportot.
    // A `testid` szándékos: a feliratok nyelvfüggők, a menü megléte nem. A csoport panelje
    // zárva indul — megnyitás után látszanak a pontjai.
    await expect(page.getByTestId('nav-platform')).toBeVisible();
    await openNavGroup(page, 'platform');
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
    await chooseLocale(page, 'hu');
    await expect(page.getByRole('heading', { name: 'Áttekintés' })).toBeVisible();

    // A váltás a nyelvválasztó MENÜN át történik (gomb → menüpont), a felhasználó útján.
    await chooseLocale(page, 'de');

    // A váltás POST-ol, a szerver írja a felhasználó sorát, és az új oldal MÁR németül jön.
    await expect(page.getByRole('heading', { name: 'Übersicht' })).toBeVisible();
    await expect(page.getByTestId('flash-success')).toBeVisible();

    // …és megmarad: egy teljes újratöltés után is német.
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Übersicht' })).toBeVisible();

    // Vissza magyarra, hogy egy elbukó futás se hagyjon idegen nyelvű állapotot a
    // következő tesztnek (a `finally` a lényeg: bukáskor is lefut).
    await chooseLocale(page, 'hu');
    await expect(page.getByRole('heading', { name: 'Áttekintés' })).toBeVisible();
});

test('a workspace-váltó CSAK a saját tagságokat kínálja', async ({ page }) => {
    await page.goto('/dashboard');

    // A váltó gombja a JELENLEGI workspace-t mutatja; a lista a saját tagságokból áll.
    await expect(page.getByTestId('workspace-current')).toHaveText('Dev workspace');

    await page.getByTestId('workspace-switch').click();

    const options = page.getByTestId('workspace-list').locator('button');

    await expect(options.filter({ hasText: 'Dev workspace' })).toHaveCount(1);
    await expect(options.filter({ hasText: 'Dev workspace' })).toHaveAttribute('aria-current', 'true');
});

test('a héjban ott a kijelentkezés (RP-initiated logout a ventSSO felé)', async ({ page }) => {
    await page.goto('/dashboard');

    // A gombot csak látjuk — megnyomni nem itt kell: a sorosan futó suite egyetlen
    // dev-munkamenetet használ, a valódi kilépést a `shell-nav.spec.ts` külön
    // böngésző-környezetben méri. A gomb az avatar-menüben él.
    await page.getByTestId('user-menu').click();
    await expect(page.getByTestId('logout')).toBeVisible();
});
