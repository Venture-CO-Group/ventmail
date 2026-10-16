import { expect, test, type Page } from '@playwright/test';
import { chooseLocale, navGroupButton, openNavGroup } from './support/shell';

/*
 * UNIFIED UI v2 · AZ APP-HÉJ a böngészőben (Figma AppShell 65:379): 248 px sidebar
 * (app-ikon + név, workspace-váltó, csoportosított menü, felhasználó), 60 px felső sáv
 * (breadcrumb, ⌘K, környezetjelző, nyelv, avatar), ventMail-színű glow-line. Felső
 * legördülő navigáció NINCS. 1024 px alatt a sidebar a menügombbal nyíló fiók.
 *
 * A `testid`-kre illesztünk (a felirat nyelvfüggő). A tesztek SOROSAN futnak: a nyelv és a
 * workspace a közös dev-munkamenethez tartozik, és minden teszt visszaállítja a kiindulást.
 */
test.describe.configure({ mode: 'serial' });

const run = Date.now().toString(36);

test.afterEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard');
    await chooseLocale(page, 'hu');
});

async function backToDevWorkspace(page: Page): Promise<void> {
    await page.goto('/dashboard');

    if (!((await page.getByTestId('workspace-switch').textContent()) ?? '').includes('Dev workspace')) {
        await page.getByTestId('workspace-switch').click();
        await page.getByTestId('workspace-list').locator('button', { hasText: 'Dev workspace' }).click();
        await expect(page.getByTestId('workspace-switch')).toContainText('Dev workspace');
    }
}

test('a keret: sidebar (ventMail, workspace, menü, felhasználó) + felső sáv — felső navigáció nincs', async ({ page }) => {
    await page.goto('/dashboard');

    const sidebar = page.getByTestId('app-nav');
    await expect(sidebar).toBeVisible();
    await expect(sidebar).toHaveJSProperty('tagName', 'ASIDE');
    const box = (await sidebar.boundingBox())!;
    expect([Math.round(box.x), Math.round(box.width)]).toEqual([0, 248]);

    await expect(sidebar.locator('.vt-appicon[data-app="mail"]')).toBeVisible();
    await expect(sidebar).toContainText('ventMail');
    await expect(page.getByTestId('workspace-switch')).toBeVisible();
    await expect(page.getByTestId('nav-overview')).toHaveAttribute('aria-current', 'page');
    await expect(sidebar.locator('.vt-sidebar__user')).toBeVisible();

    const topbar = page.locator('.vt-topbar');
    await expect(topbar).toBeVisible();
    expect(Math.round((await topbar.boundingBox())!.height)).toBe(60);
    await expect(topbar.locator('[aria-current="page"]')).not.toBeEmpty();
    await expect(page.getByTestId('locale-switch')).toBeVisible();
    await expect(page.getByTestId('user-menu')).toBeVisible();

    // A glow-line a fő oszlop tetején, a ventMail színében (app/mail).
    const glow = page.locator('.vt-shell__main > .vt-glowline');
    await expect(glow).toBeVisible();
    const [current, mail] = await glow.evaluate((el) => [
        getComputedStyle(el).getPropertyValue('--vt-app-current').trim(),
        getComputedStyle(document.documentElement).getPropertyValue('--vt-app-mail').trim(),
    ]);
    expect(mail).not.toBe('');
    expect(current.toLowerCase()).toBe(mail.toLowerCase());

    // Nincs felső legördülő navigáció és nincs régi sáv.
    await expect(page.locator('.vm-topbar, [data-testid^="nav-panel-"]')).toHaveCount(0);
});

test('a csoportok nyitnak-zárnak, az állapot megmarad, és az aktív pont csoportja nyitva van', async ({ page }) => {
    await page.goto('/dashboard');

    await openNavGroup(page, 'platform');
    await expect(page.getByTestId('nav-logs')).toBeVisible();

    // Az állapot a localStorage-ban: újratöltés után is nyitva.
    await page.reload();
    await expect(navGroupButton(page, 'platform')).toHaveAttribute('aria-expanded', 'true');

    // Zárás.
    await navGroupButton(page, 'platform').click();
    await expect(page.getByTestId('nav-logs')).toBeHidden();

    // Egy platform-oldalon a csoport magától nyitva, a pont aktív.
    await page.goto('/logs');
    await expect(navGroupButton(page, 'platform')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('nav-logs')).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.vt-topbar [aria-current="page"]')).not.toBeEmpty();
});

test('a Platform csoportban az Inbound és a Validation „hamarosan" jelzést kap, és elérhetők maradnak', async ({ page }) => {
    await page.goto('/dashboard');
    await openNavGroup(page, 'platform');

    await expect(page.getByTestId('nav-inbound').locator('.vt-badge')).toBeVisible();
    await expect(page.getByTestId('nav-validation').locator('.vt-badge')).toBeVisible();

    await page.getByTestId('nav-inbound').click();
    await expect(page).toHaveURL(/\/inbound$/);

    const response = await page.goto('/validation');
    expect(response?.status()).toBe(200);
});

test('⌘K: a paletta minden menüpontra ugrik', async ({ page }) => {
    await page.goto('/dashboard');

    await page.locator('.vt-topbar__search').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await page.keyboard.type('webhook');
    // A címkében és a leírásban is keres: a Webhookok elöl, a fejlesztői doksi (leírása említi) utána.
    await expect(dialog.getByRole('option').first()).toContainText('Webhookok');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/webhooks$/);

    // A billentyűparancs is nyitja.
    await page.keyboard.press(process.platform === 'darwin' ? 'Meta+k' : 'Control+k');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();
});

test('a környezetjelző a küldési módot mutatja a felső sávban', async ({ page }) => {
    await page.goto('/dashboard');

    const badge = page.locator('.vt-topbar .vt-badge');
    await expect(badge).toBeVisible();
    // A dev-környezetben a küldés nem éles: korlátozott mód, warning tónus.
    await expect(badge).toHaveClass(/vt-badge--(warning|success)/);
});

test('a workspace-váltó: keresés, szerepkör-jelölés, új workspace létrehozása és váltás', async ({ page }) => {
    await page.goto('/dashboard');

    await page.getByTestId('workspace-switch').click();

    const list = page.getByTestId('workspace-list');

    // A szerepköröd az adott workspace-ben látszik (a dev-belépő owner).
    await expect(list.locator('button', { hasText: 'Dev workspace' })).toContainText(/Owner|Inhaber|Propriétaire|Proprietario|Propietario|Владелец|Tulajdonos/);

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
        await expect(page.getByTestId('workspace-switch')).toContainText(name);
        await expect(page).toHaveURL(/\/dashboard$/);

        await page.getByTestId('workspace-switch').click();
        await expect(page.getByTestId('workspace-list').locator('button', { hasText: name })).toHaveAttribute('aria-current', 'true');
        await page.getByTestId('workspace-list').locator('button', { hasText: 'Dev workspace' }).click();
        await expect(page.getByTestId('workspace-switch')).toContainText('Dev workspace');
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
    await expect(page.getByTestId('locale-switch')).toHaveText('FR');
    await expect(page.getByRole('heading', { name: 'Vue d’ensemble' })).toBeVisible();
});

test('a profil-oldal ment, és a nyelv user-választásként marad meg', async ({ page }) => {
    await page.goto('/profile');

    await page.getByTestId('profile-locale').locator('xpath=descendant-or-self::select').selectOption('en');
    await page.getByTestId('profile-notif-weekly_summary').click();
    await page.getByTestId('profile-save').click();

    await expect(page.getByTestId('flash-success')).toBeVisible();
    await expect(page.getByTestId('profile-title')).toHaveText('Profile settings');

    await expect(page.getByTestId('profile-sso-link')).toHaveAttribute('target', '_blank');

    await page.getByTestId('profile-notif-weekly_summary').click();
    await page.getByTestId('profile-locale').locator('xpath=descendant-or-self::select').selectOption('hu');
    await page.getByTestId('profile-save').click();
    await expect(page.getByTestId('profile-title')).toHaveText('Profilbeállítások');
});

test.describe('1024 px alatt: a sidebar fiók', () => {
    for (const width of [1023, 390]) {
        test(`${width} px: a menügomb nyitja, a pont navigál, Esc zár`, async ({ page }) => {
            await page.setViewportSize({ width, height: 844 });
            await page.goto('/dashboard');

            await expect(page.getByTestId('app-nav')).toBeHidden();
            const menu = page.locator('.vt-topbar__menu');
            await expect(menu).toBeVisible();

            // Semmi nem lóg ki vízszintesen.
            expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);

            await menu.click();
            await expect(page.getByTestId('app-nav')).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(page.getByTestId('app-nav')).toBeHidden();
            await expect(menu).toBeFocused();

            await openNavGroup(page, 'platform');
            await page.getByTestId('nav-domains').click();
            await expect(page).toHaveURL(/\/domains$/);
            await expect(page.getByTestId('app-nav')).toBeHidden();
        });
    }

    test('390 px: a nyelv a fiókmenüből váltható', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');

        await expect(page.getByTestId('locale-switch')).toBeHidden();
        await chooseLocale(page, 'en');
        await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();
    });
});
