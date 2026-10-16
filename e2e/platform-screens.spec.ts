import { expect, test } from '@playwright/test';
import { clickNav } from './support/shell';

/*
 * M4-1 · A PLATFORM ÜZEMELTETŐI KÉPERNYŐI a böngészőben.
 *
 * A `testid`-kre illesztünk, nem feliratra: a felület hat nyelvű, és egy szövegre kötött
 * teszt a nyelvváltás után elbukna — pont azt a funkciót büntetve, ami miatt a fordítás
 * készült.
 */
/*
 | A KOMPONENSEK DOM-JA nem egyforma, és ezt egyszer érdemes leírni: a `VtInput` MAGA az
 | `<input>` (a `data-testid` rajta ül), a `VtCheckbox` és a `VtSelect` viszont burkoló
 | elemet ad, tehát ott a belső vezérlőre kell illeszteni.
 */

test('a menüből minden platform-képernyő elérhető', async ({ page }) => {
    await page.goto('/dashboard');

    for (const [testid, url] of [
        ['nav-domains', '/domains'],
        ['nav-api-keys', '/api-keys'],
        ['nav-smtp', '/smtp-credentials'],
        ['nav-suppressions', '/suppressions'],
        ['nav-webhooks', '/webhooks'],
        ['nav-ip-pools', '/ip-pools'],
        ['nav-deliverability', '/deliverability'],
        ['nav-validation', '/validation'],
        ['nav-billing', '/billing'],
    ] as const) {
        await clickNav(page, testid === 'nav-validation' || testid === 'nav-billing' ? 'settings' : 'platform', testid);
        await expect(page).toHaveURL(new RegExp(`${url}$`));
        // A héj minden képernyőn ott van: enélkül a felhasználó zsákutcába kerülne.
        await expect(page.getByTestId('app-nav')).toBeVisible();
    }
});

test('az API-kulcs kiállításkor EGYSZER látszik', async ({ page }) => {
    await page.goto('/api-keys');

    // v1.1: a kiállító űrlap modalban nyílik (a mockup szerint az oldalfej gombjáról).
    await page.getByTestId('key-new').click();
    await page.getByTestId('key-name').fill('E2E kulcs');
    // A `VtCheckbox` belső `<input>`-ja vizuálisan rejtett (a pipát a `span` rajzolja),
    // ezért a CÍMKÉRE kattintunk — ahogy a felhasználó is tenné.
    await page.getByTestId('scope-messages:send').click();
    await page.getByTestId('key-submit').click();

    const issued = page.getByTestId('issued-key-value');
    await expect(issued).toBeVisible();

    const plain = (await issued.textContent())?.trim() ?? '';
    expect(plain).toMatch(/^vm_live_/);

    // ÚJRATÖLTÉS UTÁN a nyers kulcs SEHOL: a flash egyszer él, a tábla csak hasht tárol.
    await page.reload();
    await expect(page.getByTestId('issued-key-value')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText(plain);
});

test('a tiltólista felvesz, és a PANASZT nem engedi feloldani', async ({ page }) => {
    await page.goto('/suppressions');

    // v1.1: a felvétel modalban; a felvett cím a SAJÁT listáján (kézi tiltások) jelenik meg.
    await page.getByTestId('suppression-new').click();
    await page.getByTestId('suppression-email').fill('e2e-tiltott@pelda.hu');
    await page.getByTestId('suppression-submit').click();

    await expect(page).toHaveURL(/list=manual/);
    await expect(page.getByTestId('suppressions-table')).toContainText('e2e-tiltott@pelda.hu');

    // A kézi tiltás feloldható — de csak INDOKLÁSSAL (auditált).
    await expect(page.getByTestId('remove-e2e-tiltott@pelda.hu')).toBeVisible();
    await page.getByTestId('remove-e2e-tiltott@pelda.hu').click();
    await expect(page.getByTestId('suppression-delete-confirm')).toBeDisabled();
    await page.getByTestId('suppression-justification').fill('E2E: tévesen felvett cím');
    await page.getByTestId('suppression-delete-confirm').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();
});

test('a webhook-végpont SSRF-es címre nem jön létre', async ({ page }) => {
    await page.goto('/webhooks');

    await page.getByTestId('endpoint-new').click();
    await page.getByTestId('endpoint-name').fill('Belső');
    await page.getByTestId('endpoint-url').fill('http://169.254.169.254/latest/meta-data/');
    await page.getByTestId('endpoint-submit').click();

    // A hiba OTT jelenik meg, ahol beírták — nem a hatodik sikertelen kézbesítésnél.
    await expect(page.getByTestId('flash-error')).toBeVisible();
    await expect(page.getByTestId('endpoints-empty')).toBeVisible();
});

test('a címellenőrzés javaslatot ad az elgépelt címre, és a javaslat egy kattintás', async ({ page }) => {
    await page.goto('/validation');

    await page.getByTestId('validation-email').fill('valaki@gmial.com');
    await page.getByTestId('validation-submit').click();

    /*
     | HOSSZABB HATÁRIDŐ, és nem lustaságból: a dev-stack VALÓDI DNS/MX-lekérdezést futtat
     | (a CI-ban `DOMAINS_RESOLVER=fake`), egy nem létező domainre (`gmial.com`) pedig a
     | feloldó a negatív választ lassan adja — a teljes suite alatt mérve 5 másodperc fölött
     | is. Az alapértelmezett határidővel a teszt akkor bukott el, amikor a kérés MÉG FUTOTT:
     | a hiba ott látszott, ahol nem volt.
     */
    await expect(page.getByTestId('validation-verdict')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('verdict-suggestion')).toContainText('valaki@gmail.com');

    // A JAVASLAT ÚJRAELLENŐRZÉSE: ez menti meg a címet, ami különben örökre bounce-olna.
    await page.getByTestId('verdict-use-suggestion').click();
    await expect(page.getByTestId('verdict-result')).not.toContainText('undeliverable');
});

