import { expect, test } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 9. pont — FEJLESZTŐI DOKSI a böngészőben.
 *
 * A referencia minden szerződésbeli végpontot listáz (a menü darabszáma = a szerver által
 * feldolgozott OpenAPI darabszáma; a szerződéssel való egyezést a Pest `DeveloperDocsDriftTest`
 * méri), az útmutatók egy forrásfájlból jönnek, van kereső, és a kódminta-MÁSOLÁS a
 * vágólapra pontosan azt teszi, ami a képernyőn látszik.
 */
test.use({ permissions: ['clipboard-read', 'clipboard-write'] });

test('a menü minden szerződésbeli végpontot listáz, a „hamarosan" végpontok jelölve', async ({ page }) => {
    await page.goto('/developers');

    const nav = page.getByTestId('docs-nav');
    const expected = Number(await nav.getAttribute('data-operation-count'));

    expect(expected).toBeGreaterThan(20);
    await expect(nav.locator('[data-testid^="docs-op-"]')).toHaveCount(expected);

    // „Hamarosan": a bejövő route és a címellenőrzés útmutatója + a szerződés végpontjai, jelölve.
    await page.getByTestId('docs-soon-validation').click();
    await expect(page.getByTestId('docs-guide-body-validation')).toBeVisible();
    await page.getByTestId('docs-soon-operations').locator('button').first().click();
    await expect(page.getByTestId('docs-soon-banner')).toBeVisible();
    await expect(page.getByTestId('docs-url')).toContainText('/validate');
});

test('az útmutatók: hitelesítés + scope-ok, Messages (o:/h:/v:, batch), SMTP, webhook, hibakódok, rate-limit', async ({ page }) => {
    await page.goto('/developers');

    await page.getByTestId('docs-guide-auth').click();
    await expect(page.getByTestId('docs-scopes')).toContainText('messages:send');
    await expect(page.getByTestId('docs-scopes')).toContainText('POST /messages');

    await page.getByTestId('docs-guide-messages').click();
    await expect(page.getByTestId('docs-guide-prose')).toContainText('recipient-variables');
    await expect(page.getByTestId('docs-controls')).toContainText('o:testmode');
    await expect(page.getByTestId('docs-controls')).toContainText('h:X-*');

    await page.getByTestId('docs-guide-smtp').click();
    await expect(page.getByTestId('docs-guide-prose')).toContainText('587');
    await expect(page.getByTestId('docs-guide-prose')).toContainText('465');

    await page.getByTestId('docs-guide-webhooks').click();
    await expect(page.getByTestId('docs-guide-prose')).toContainText('X-VentMail-Signature');
    await expect(page.getByTestId('docs-guide-prose')).toContainText('dead-letter');

    await page.getByTestId('docs-guide-errors').click();
    await expect(page.getByTestId('docs-error-codes')).toContainText('IDEMPOTENCY_KEY_REUSED');

    await page.getByTestId('docs-guide-rateLimits').click();
    await expect(page.getByTestId('docs-guide-prose')).toContainText('429');
});

test('a kereső szűri az útmutatókat és a végpontokat; üres találatnál jelez', async ({ page }) => {
    await page.goto('/developers');

    const search = page.getByTestId('docs-search');

    await search.fill('smtp');
    await expect(page.getByTestId('docs-guide-smtp')).toBeVisible();
    await expect(page.getByTestId('docs-guide-errors')).toHaveCount(0);

    await search.fill('/suppressions');
    await expect(page.locator('[data-testid^="docs-op-"]')).toHaveCount(3);

    await search.fill('nincs-ilyen-zzz');
    await expect(page.getByTestId('docs-search-empty')).toBeVisible();

    await search.fill('');
    await expect(page.getByTestId('docs-guide-auth')).toBeVisible();
});

test('a kódminta nyelvválasztóval, és a MÁSOLÁS pontosan a látható mintát teszi a vágólapra', async ({ page }) => {
    await page.goto('/developers');

    await page.getByTestId('docs-op-post-messages').click();

    const code = page.getByTestId('docs-code');
    const radios = page.getByTestId('docs-language').getByRole('radio');

    await expect(radios).toHaveCount(4);
    await expect(page.getByTestId('docs-language')).toContainText('JavaScript');

    for (const [index, needle] of [[0, 'curl -X POST'], [1, 'curl_init'], [2, 'fetch(']] as const) {
        await radios.nth(index).click();
        await expect(code).toContainText(needle);

        await page.getByTestId('docs-code-copy').click();
        const clipboard = await page.evaluate(() => navigator.clipboard.readText());

        expect(clipboard).toBe(await code.textContent());
    }

    // A webhook-ellenőrző minta másolása is.
    await page.getByTestId('docs-guide-webhooks').click();
    await page.getByTestId('docs-verify-copy').click();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(await page.getByTestId('docs-verify-code').textContent());
});
