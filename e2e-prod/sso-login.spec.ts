import { expect, test } from '@playwright/test';
import { execSync } from 'node:child_process';

/*
 | ÉLES: belépés a prod ventSSO-n → consent (név + e-mail, a céllal) → dashboard →
 | kijelentkezés (RP-initiated: a ventSSO munkamenete is záródik).
 */
const email = process.env.E2E_EMAIL ?? '';
const password = process.env.E2E_PW ?? '';
const shots = process.env.E2E_SHOTS ?? 'test-results/prod';

test('ventSSO belépés, consent, dashboard, kijelentkezés', async ({ page }) => {
    test.skip(email === '' || password === '', 'E2E_EMAIL / E2E_PW nincs megadva');

    page.on('console', (m) => { if (/Content Security Policy|form-action/i.test(m.text())) console.log('CSP:', m.text().slice(0, 300)); });
    await page.goto('/auth/redirect');
    await expect(page).toHaveURL(/^https:\/\/sso\.ventureco\.app\//);
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.screenshot({ path: `${shots}/1-sso-login.png` });
    await page.locator('form[data-login-form] button[type=submit]').click();

    // Új felhasználónál a ventSSO a hatályos jogi dokumentumok elfogadását kéri.
    if (await page.getByRole('heading', { name: /updated terms|frissített|feltétel/i }).isVisible({ timeout: 5000 }).catch(() => false)) {
        for (const box of await page.getByRole('checkbox').all()) {
            await box.check();
        }
        await page.screenshot({ path: `${shots}/1b-terms.png`, fullPage: true });
        await page.locator('main button[type=submit], main [type=submit]').first().click();
    }

    // CONSENT: a ventMail CSAK a nevet és az e-mailt kéri, a megadott céllal.
    await expect(page.locator('#consent-approve')).toBeVisible();
    const labels = (await page.locator('.consent__label').allInnerTexts()).map((t) => t.trim());
    expect(labels).toHaveLength(2);
    expect(labels.join('|')).toMatch(/(Teljes név|Full name)/);
    expect(labels.join('|')).toMatch(/(E-mail-cím|E-mail address)/);
    await expect(page.locator('.consent__purpose').first()).toContainText('A fiókod azonosításához');
    await page.screenshot({ path: `${shots}/2-consent.png`, fullPage: true });
    await page.locator('button[form="consent-approve"]').click();

    await expect(page).toHaveURL(/^https:\/\/app\.ventmail\.app\//);

    // A tenant-tagság a ventMail SAJÁT workspace_members táblájából jön (nem a tokenből).
    execSync(`ssh -o BatchMode=yes mail 'cd /srv/apps/ventmail && VENTMAIL_TAG=$(cat .deployed-tag) VENTMAIL_COLOR=ops docker compose -p ventmail-ops -f compose.prod.yml --profile ops run --rm --no-deps -T ops artisan ventmail:member:grant venture ${email} owner'`, { stdio: 'ignore' });

    await page.goto('/dashboard');
    await expect(page.getByTestId('app-nav')).toBeVisible();
    await page.screenshot({ path: `${shots}/3-dashboard.png`, fullPage: true });

    // RP-initiated logout: a böngésző a ventSSO end_session végpontjára megy, onnan vissza.
    // (A köztes 302 URL-je nem „commitol", ezért a KÉRÉST várjuk, utána a visszaérkezést.)
    await Promise.all([
        page.waitForRequest(/^https:\/\/sso\.ventureco\.app\/oauth\/logout\?client_id=/),
        page.getByTestId('logout').click(),
    ]);
    await page.waitForURL(/^https:\/\/app\.ventmail\.app\/?$/);
    await page.screenshot({ path: `${shots}/4-after-logout.png` });

    // A ventMail munkamenete megszűnt: a védett oldal újra a belépésre visz.
    // A ventMail ÉS a ventSSO munkamenete is megszűnt: a védett oldal a ventSSO login-oldalára visz.
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/^https:\/\/sso\.ventureco\.app\/login/);
    await page.screenshot({ path: `${shots}/5-dashboard-after-logout.png` });
});
