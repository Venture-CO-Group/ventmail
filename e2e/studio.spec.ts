import { expect, test } from '@playwright/test';
import { clickNav } from './support/shell';

/*
 * M4-3 · A STUDIO FELÜLETE a böngészőben.
 *
 * A blokk-szerkesztő tétje: amit itt összeraknak, az megy ki levélként. Ezért a spec nem
 * azt méri, hogy „megjelenik-e a gomb", hanem hogy a MENTÉS ÉRVÉNYES FÁT ír, és hogy az
 * előnézet a valódi fordítóból jön.
 */
/*
 | A NEVEK FUTÁSONKÉNT EGYEDIEK.
 |
 | A `templates` és a `campaigns` táblán részleges unique index van a `(workspace_id, name)`
 | páron — helyesen: két azonos nevű kampány a listában megkülönböztethetetlen. A
 | böngésző-suite viszont UGYANAZT a tenantot használja minden futásban, tehát fix névvel a
 | MÁSODIK futás ütközne, és a hiba a szerkesztőben jelenne meg, nem ott, ahol keletkezett.
 */
const run = Date.now().toString(36);

test('a Studio menüpontjai elérhetők', async ({ page }) => {
    await page.goto('/dashboard');

    for (const [testid, url] of [
        ['nav-audience', '/audience'],
        ['nav-templates', '/templates'],
        ['nav-campaigns', '/campaigns'],
        ['nav-journeys', '/journeys'],
        ['nav-analytics', '/analytics'],
    ] as const) {
        await clickNav(page, 'studio', testid);
        await expect(page).toHaveURL(new RegExp(`${url}$`));
        await expect(page.getByTestId('app-nav')).toBeVisible();
    }
});

test('az új sablon ÉRVÉNYES fával indul, és az előnézet a valódi fordítóból jön', async ({ page }) => {
    await page.goto('/templates');

    const name = `E2E sablon ${run}`;

    await page.getByTestId('template-new').click();
    await page.getByTestId('create-blank').click();
    await page.getByTestId('template-name').fill(name);
    await page.getByTestId('template-subject').fill('E2E tárgy');
    await page.getByTestId('template-submit').click();

    // A LÉTREHOZÁS a szerkesztőbe visz — és a marketing sablon már tartalmazza a kötelező
    // láblécet (postai cím, leiratkozó, beállítások), különben létre sem jöhetne.
    await expect(page.getByTestId('editor-name')).toHaveValue(name);
    await expect(page.getByTestId('section-0')).toBeVisible();
    await expect(page.locator('[data-testid="editor-canvas"] [data-type="footer"]')).toHaveCount(1);

    await page.getByTestId('editor-preview').click();
    await expect(page.getByTestId('preview-frame')).toHaveAttribute('srcdoc', /<!doctype html>/);
});

test('a szerkesztő blokkot ad hozzá, és a mentés után is ott van', async ({ page }) => {
    await page.goto('/templates');
    await page.getByTestId('template-new').click();
    await page.getByTestId('template-name').fill(`E2E blokkok ${run}`);
    await page.getByTestId('template-subject').fill('Blokkok');
    await page.getByTestId('template-submit').click();

    // KATTINTÁSSAL beszúrt címsor (a húzás billentyűzetes megfelelője): a kijelölt bekezdés után.
    await page.getByTestId('block-0-0-0').click();
    await page.getByTestId('palette-heading').click();
    await expect(page.getByTestId('block-0-0-1')).toHaveAttribute('data-type', 'heading');

    const heading = page.getByTestId('rich-0-0-1');
    await heading.click();
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type('E2E cím');

    await expect(page.getByTestId('save-status')).toHaveAttribute('data-state', 'saved', { timeout: 15_000 });

    await page.reload();
    await expect(page.getByTestId('rich-0-0-1')).toHaveText('E2E cím');
});

test('a kampány célzása a becsült közönséggel jelenik meg', async ({ page }) => {
    await page.goto('/campaigns');

    // v1.1 · az új kampány űrlapja a fejléc gombjával nyílik (üres listán magától látszik).
    await page.getByTestId('campaign-new').click();
    await page.getByTestId('campaign-name').fill(`E2E kampány ${run}`);
    await page.getByTestId('campaign-subject').fill('E2E tárgy');
    await page.getByTestId('campaign-submit').click();

    // A checklist első (hiányos) szakasza, a címzettek, nyitva van — benne a becslés.
    await expect(page.getByTestId('campaign-estimate')).toBeVisible();
    await expect(page.getByTestId('campaign-status')).toHaveAttribute('data-status', 'draft');
});

test('a journey a felületről aktiválható, ha a lánca érvényes', async ({ page }) => {
    await page.goto('/journeys');

    await page.getByTestId('journey-name').fill(`E2E journey ${run}`);
    await page.getByTestId('journey-submit').click();

    // LÉPÉS NÉLKÜL is aktiválható (a lánc üres, tehát nincs benne kör) — a lényeg, hogy a
    // gomb a szerverrel beszél, és az állapot tényleg átvált.
    await expect(page.getByTestId('journey-status')).toContainText('draft');

    await page.getByTestId('journey-activate').click();

    await expect(page.getByTestId('flash-success')).toBeVisible();
    await expect(page.getByTestId('journey-status')).toContainText('active');

    await page.getByTestId('journey-pause').click();
    await expect(page.getByTestId('journey-status')).toContainText('paused');
});

