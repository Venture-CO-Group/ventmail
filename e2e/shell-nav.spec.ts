import { expect, test, type Page } from '@playwright/test';
import { chooseLocale, openNavGroup } from './support/shell';

/*
 * v1.1 · SHELL — a FELSŐ NAVIGÁCIÓ a böngészőben: csoport-panelek, billentyűzet, workspace-
 * váltó + létrehozás, avatar-menü, nyelvváltás, kijelentkezés, mobil hamburger.
 *
 * A `testid`-kre illesztünk (a felirat nyelvfüggő). A tesztek SOROSAN futnak: a nyelv és a
 * workspace a közös dev-munkamenethez tartozik, és minden teszt visszaállítja a kiindulást.
 */
test.describe.configure({ mode: 'serial' });

const run = Date.now().toString(36);

test.afterEach(async ({ page }) => {
    await page.goto('/dashboard');
    await chooseLocale(page, 'hu');
});

async function backToDevWorkspace(page: Page): Promise<void> {
    await page.goto('/dashboard');

    if ((await page.getByTestId('workspace-current').textContent())?.trim() !== 'Dev workspace') {
        await page.getByTestId('workspace-switch').click();
        await page.getByTestId('workspace-list').locator('button', { hasText: 'Dev workspace' }).click();
        await expect(page.getByTestId('workspace-current')).toHaveText('Dev workspace');
    }
}

test('a sáv elemei: logó, workspace-váltó, Overview, csoportok, nyelv, avatar — oldalsáv nincs', async ({ page }) => {
    await page.goto('/dashboard');

    const bar = page.getByTestId('app-nav');

    await expect(bar).toBeVisible();
    await expect(page.getByTestId('workspace-switch')).toBeVisible();
    await expect(page.getByTestId('nav-overview')).toBeVisible();
    await expect(page.getByTestId('nav-group-studio')).toBeVisible();
    await expect(page.getByTestId('nav-group-platform')).toBeVisible();
    await expect(page.getByTestId('nav-group-settings')).toBeVisible();
    await expect(page.getByTestId('locale-switch')).toBeVisible();
    await expect(page.getByTestId('user-menu')).toBeVisible();

    // A sáv VÍZSZINTES: a magassága kisebb, mint a szélessége fele (nem oldalsáv).
    const box = await bar.boundingBox();

    expect(box).not.toBeNull();
    expect(box!.height).toBeLessThan(box!.width / 4);

    // A workspace-váltó KÖZVETLENÜL az Overview előtt áll.
    const switchBox = await page.getByTestId('workspace-switch').boundingBox();
    const overviewBox = await page.getByTestId('nav-overview').boundingBox();

    expect(switchBox!.x).toBeLessThan(overviewBox!.x);
});

test('a Studio panel hoverre és kattintásra nyílik, leírással; Esc zár és a fókusz visszatér', async ({ page }) => {
    await page.goto('/dashboard');

    const trigger = page.getByTestId('nav-group-studio');

    // Hover: nyílik.
    await trigger.hover();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('nav-campaigns')).toBeVisible();
    // Egysoros leírás a panelben.
    await expect(page.getByTestId('nav-campaigns').locator('.vt-navitem__desc')).not.toBeEmpty();

    // Kattintás: rögzíti (nem zárja be).
    await trigger.click();
    await page.mouse.move(0, 0);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');

    // Esc: zár, és a fókusz a gombra tér vissza.
    await page.keyboard.press('Escape');
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(trigger).toBeFocused();
});

test('a csoport billentyűzettel járható: Enter nyit, Tab a linkekre lép, Esc zár', async ({ page }) => {
    await page.goto('/dashboard');

    const trigger = page.getByTestId('nav-group-platform');

    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');

    // ARIA: a gomb a panelre mutat.
    const panelId = await trigger.getAttribute('aria-controls');

    expect(panelId).toBeTruthy();
    await expect(page.locator(`#${panelId}`)).toBeVisible();

    await page.keyboard.press('Tab');
    await expect(page.locator(`#${panelId} a`).first()).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(trigger).toBeFocused();
});

test('csak egy panel nyitott egyszerre', async ({ page }) => {
    await page.goto('/dashboard');

    await openNavGroup(page, 'studio');
    await openNavGroup(page, 'platform');

    await expect(page.getByTestId('nav-group-studio')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('nav-group-platform')).toHaveAttribute('aria-expanded', 'true');
});

test('a Platform csoportban az Inbound és a Validation „hamarosan" jelzést kap, és elérhetők maradnak', async ({ page }) => {
    await page.goto('/dashboard');
    await openNavGroup(page, 'platform');

    await expect(page.getByTestId('nav-inbound').locator('.vm-navitem__soon')).toBeVisible();
    await expect(page.getByTestId('nav-validation').locator('.vm-navitem__soon')).toBeVisible();

    await page.getByTestId('nav-inbound').click();
    await expect(page).toHaveURL(/\/inbound$/);

    // A régi útvonal közvetlenül is él.
    const response = await page.goto('/validation');

    expect(response?.status()).toBe(200);
});

test('a workspace-váltó: keresés, szerepkör-jelölés, új workspace létrehozása és váltás', async ({ page }) => {
    await page.goto('/dashboard');

    await page.getByTestId('workspace-switch').click();

    const list = page.getByTestId('workspace-list');

    // A szerepköröd az adott workspace-ben látszik (a dev-belépő owner).
    await expect(list.locator('button', { hasText: 'Dev workspace' })).toContainText(/Owner|Inhaber|Propriétaire|Proprietario|Propietario|Владелец/);

    // Keresés: nem illeszkedő szöveg → üres lista.
    await page.getByTestId('workspace-search').fill('nincs-ilyen-workspace');
    await expect(list.locator('button')).toHaveCount(0);
    await page.getByTestId('workspace-search').fill('');

    // Új workspace.
    const name = `E2E ws ${run}`;

    await page.getByTestId('workspace-new').click();
    await page.getByTestId('workspace-new-name').fill(name);
    await page.getByTestId('workspace-new-submit').click();

    try {
        // A váltó rögtön az újra áll, a dashboardon vagyunk.
        await expect(page.getByTestId('workspace-current')).toHaveText(name);
        await expect(page).toHaveURL(/\/dashboard$/);

        // Visszaváltás a Dev workspace-re a listából.
        await page.getByTestId('workspace-switch').click();
        await expect(page.getByTestId('workspace-list').locator('button', { hasText: name })).toHaveAttribute('aria-current', 'true');
        await page.getByTestId('workspace-list').locator('button', { hasText: 'Dev workspace' }).click();
        await expect(page.getByTestId('workspace-current')).toHaveText('Dev workspace');
    } finally {
        await backToDevWorkspace(page);
    }
});

test('az avatar-menü: név, e-mail, profil, ventSSO-fiók új lapon, verzió', async ({ page }) => {
    await page.goto('/dashboard');

    await page.getByTestId('user-menu').click();

    await expect(page.getByTestId('user-name')).not.toBeEmpty();
    await expect(page.getByTestId('user-email')).toContainText('@');

    const account = page.getByTestId('user-account');

    await expect(account).toHaveAttribute('target', '_blank');
    await expect(account).toHaveAttribute('href', /\/account$/);
    await expect(account).toHaveAttribute('rel', /noopener/);

    await expect(page.getByTestId('app-version')).toContainText('ventMail');
    await expect(page.getByTestId('logout')).toBeVisible();

    // A menü nyilakkal járható, Esc zár.
    await page.keyboard.press('ArrowDown');
    await expect(page.getByTestId('user-profile')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('user-menu')).toBeFocused();

    // A Profilbeállítások link a /profile oldalra visz.
    await page.getByTestId('user-menu').click();
    await page.getByTestId('user-profile').click();
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByTestId('profile-title')).toBeVisible();
});

test('a nyelvváltó mind a hét nyelvet kínálja, és a váltás a felületen is látszik', async ({ page }) => {
    await page.goto('/dashboard');

    await page.getByTestId('locale-switch').click();

    for (const code of ['hu', 'en', 'de', 'fr', 'it', 'es', 'ru']) {
        await expect(page.getByTestId(`locale-option-${code}`)).toBeVisible();
    }

    await page.getByTestId('locale-option-fr').click();
    await expect(page.getByTestId('locale-current')).toHaveText('FR');
    await expect(page.getByRole('heading', { name: 'Vue d’ensemble' })).toBeVisible();
});

test('a profil-oldal ment, és a nyelv user-választásként marad meg', async ({ page }) => {
    await page.goto('/profile');

    await page.getByTestId('profile-locale').locator('select').selectOption('en');
    await page.getByTestId('profile-notif-weekly_summary').click();
    await page.getByTestId('profile-save').click();

    await expect(page.getByTestId('flash-success')).toBeVisible();
    await expect(page.getByTestId('profile-title')).toHaveText('Profile settings');

    // A ventSSO-fiók kártya link új lapon nyílik.
    await expect(page.getByTestId('profile-sso-link')).toHaveAttribute('target', '_blank');

    // Visszaállítás (a heti összefoglaló kapcsoló is).
    await page.getByTestId('profile-notif-weekly_summary').click();
    await page.getByTestId('profile-locale').locator('select').selectOption('hu');
    await page.getByTestId('profile-save').click();
    await expect(page.getByTestId('profile-title')).toHaveText('Profilbeállítások');
});

test.describe('mobil nézet (hamburger)', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('a hamburger harmonikaszerű csoportokat nyit, az állapot megmarad, Esc zár', async ({ page }) => {
        await page.goto('/dashboard');

        // Mobilon nincs asztali csoport-sor.
        await expect(page.getByTestId('nav-group-studio')).toHaveCount(0);

        const burger = page.getByTestId('nav-hamburger');

        await expect(burger).toBeVisible();
        await burger.click();
        await expect(burger).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByTestId('nav-mobile')).toBeVisible();

        const studio = page.getByTestId('nav-group-studio');

        await expect(studio).toHaveAttribute('aria-expanded', 'false');
        await studio.click();
        await expect(studio).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByTestId('nav-campaigns')).toBeVisible();

        // A nyitott csoport a localStorage-ban marad: újratöltés után is nyitva.
        await page.reload();
        await page.getByTestId('nav-hamburger').click();
        await expect(page.getByTestId('nav-group-studio')).toHaveAttribute('aria-expanded', 'true');

        // A nyelvválasztó a hamburger-panelben él.
        await expect(page.getByTestId('locale-option-fr')).toBeVisible();

        // Esc zár, a fókusz visszatér a hamburgerre.
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('nav-mobile')).toHaveCount(0);
        await expect(page.getByTestId('nav-hamburger')).toBeFocused();

        // Állapot-takarítás: a következő tesztnek zárt csoportokkal induljon.
        await page.getByTestId('nav-hamburger').click();
        await page.getByTestId('nav-group-studio').click();
    });

    test('mobilon a menüpont kattintás után navigál, és a panel zárul', async ({ page }) => {
        await page.goto('/dashboard');
        await page.getByTestId('nav-hamburger').click();
        await page.getByTestId('nav-group-settings').click();
        await page.getByTestId('nav-profile').click();

        await expect(page).toHaveURL(/\/profile$/);
        await expect(page.getByTestId('nav-mobile')).toHaveCount(0);

        await page.getByTestId('nav-hamburger').click();
        await page.getByTestId('nav-group-settings').click();
    });

    test('a profil-oldal mobilon sem lóg ki vízszintesen', async ({ page }) => {
        await page.goto('/profile');

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

        expect(overflow).toBeLessThanOrEqual(0);
    });
});
