import { expect, test } from '@playwright/test';

/*
 * v1.1 · 7. MODUL — a Platform képernyői Mailgun-szinten, a böngészőben.
 *
 * Testid-re illesztünk (a felület hét nyelvű). A suite egy dev-tenanton fut, ezért minden
 * teszt a SAJÁT, egyedi nevű adatát hozza létre és arra szűr.
 */
const run = Date.now().toString(36);

test('domainek: a kiválasztott domain rekordjai másolhatók, élő állapottal és újraellenőrzéssel', async ({ page }) => {
    await page.goto('/domains/wizard');
    await page.getByTestId('domain-input').fill(`e2e-${run}.pelda.hu`);
    await page.getByTestId('domain-submit').click();
    await expect(page.getByTestId('dns-records')).toBeVisible();

    await page.goto('/domains');
    await page.getByTestId(`domain-item-e2e-${run}.pelda.hu`).click();

    await expect(page.getByTestId('domain-name')).toHaveText(`e2e-${run}.pelda.hu`);

    for (const key of ['spf', 'dkim', 'dmarc', 'return_path_transactional', 'tracking']) {
        await expect(page.getByTestId(`dns-row-${key}`)).toBeVisible();
    }

    // A rekord ÉRTÉKE egy kattintással másolható.
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.getByTestId('copy-value-spf').click();
    await expect(page.getByTestId('copy-value-spf')).toHaveAttribute('data-copied', 'true');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toContain('v=spf1');

    // Újraellenőrzés: a listára tér vissza, ugyanazon a domainen.
    await page.getByTestId('verify-button').click();
    await expect(page).toHaveURL(/\/domains\?domain=/);
    await expect(page.getByTestId('domain-name')).toHaveText(`e2e-${run}.pelda.hu`);
    await expect(page.getByTestId('dns-state-spf')).toHaveAttribute('data-state', /failed|verified|pending/);

    // DKIM-rotáció megerősítéssel.
    const before = await page.getByTestId('dkim-card').innerText();
    await page.getByTestId('rotate-button').click();
    await page.getByTestId('rotate-confirm').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();
    await expect(page.getByTestId('dkim-card')).not.toHaveText(before);
});

test('API-kulcs: IP-allowlist, egyszer látszik, visszavonható', async ({ page }) => {
    await page.goto('/api-keys');

    await page.getByTestId('key-new').click();
    await page.getByTestId('key-name').fill(`allowlist-${run}`);
    await page.getByTestId('scope-events:read').click();
    await page.getByTestId('key-allowlist').fill('203.0.113.0/24');
    await page.getByTestId('key-submit').click();

    const plain = ((await page.getByTestId('issued-key-value').textContent()) ?? '').trim();
    expect(plain).toMatch(/^vm_live_/);

    const prefix = plain.slice(0, 16);
    await expect(page.getByTestId('keys-table')).toContainText('203.0.113.0/24');
    await expect(page.getByTestId(`key-state-${prefix}`)).toHaveAttribute('data-state', 'active');

    await page.getByTestId(`revoke-${prefix}`).click();
    await page.getByTestId('revoke-confirm').click();
    await expect(page.getByTestId(`key-state-${prefix}`)).toHaveAttribute('data-state', 'revoked');

    // Újratöltés után a nyers kulcs SEHOL.
    await page.reload();
    await expect(page.locator('body')).not.toContainText(plain);
});

test('SMTP: a rotáció új jelszót ad, egyszer', async ({ page }) => {
    await page.goto('/smtp-credentials');

    await expect(page.getByTestId('smtp-submission')).toContainText('587');

    await page.getByTestId('credential-name').fill(`smtp-${run}`);
    await page.getByTestId('credential-submit').click();

    const username = ((await page.getByTestId('issued-username').textContent()) ?? '').trim();
    const password = ((await page.getByTestId('issued-password').textContent()) ?? '').trim();
    expect(password.length).toBeGreaterThan(20);

    await page.getByTestId(`rotate-${username}`).click();
    await page.getByTestId('smtp-confirm').click();

    // Az új hitelesítő ÚJ felhasználónevet kap (a régi visszavonva).
    await expect(page.getByTestId('issued-username')).not.toHaveText(username);
    const rotated = ((await page.getByTestId('issued-password').textContent()) ?? '').trim();
    expect(rotated).not.toBe(password);
    await expect(page.getByTestId(`rotate-${username}`)).toBeHidden();

    await page.reload();
    await expect(page.locator('body')).not.toContainText(rotated);
});
