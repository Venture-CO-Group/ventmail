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
    await expect(page.getByTestId('dns-state-spf')).toHaveAttribute('data-state', /failed|verified|propagating/);

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

test('webhook: eseménytípusok, titok-rotáció türelmi ablakkal, teszt-esemény a naplóban, törlés', async ({ page }) => {
    await page.goto('/webhooks');

    await page.getByTestId('endpoint-new').click();
    await page.getByTestId('endpoint-name').fill(`hook-${run}`);
    // Literál publikus IP: az SSRF-őr valódi DNS-t nem kérdez; a kézbesítés eredménye itt mellékes.
    await page.getByTestId('endpoint-url').fill(`https://93.184.216.34/e2e/${run}`);
    await page.getByTestId('event-delivered').click();
    await page.getByTestId('endpoint-submit').click();

    await expect(page.getByTestId('issued-secret-value')).toContainText('whsec_');
    const first = ((await page.getByTestId('issued-secret-value').textContent()) ?? '').trim();

    await page.getByTestId('endpoint-list').getByText(`hook-${run}`).click();
    await expect(page.getByTestId('endpoint-url-title')).toContainText(run);

    // Eseménytípus hozzáadása és mentése.
    await page.getByTestId('edit-event-opened').click();
    await page.getByTestId('events-save').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();

    // Rotáció türelmi ablakkal: új titok egyszer, a maszk az utolsó négy karaktert mutatja.
    await page.getByTestId('secret-rotate').click();
    await page.getByTestId('rotate-secret-confirm').click();
    await expect(page.getByTestId('issued-secret-value')).not.toHaveText(first);
    const rotated = ((await page.getByTestId('issued-secret-value').textContent()) ?? '').trim();
    await page.getByTestId('endpoint-list').getByText(`hook-${run}`).click();
    await expect(page.getByTestId('secret-hint')).toContainText(rotated.slice(-4));

    // Teszt-esemény: levél nélkül, a kézbesítési naplóban jelölve.
    await page.getByTestId('test-send').click();
    await expect(page.getByTestId('deliveries-table').locator('[data-state="test"]').first()).toBeVisible({ timeout: 20_000 });

    // Takarítás (a platform-screens üres végpontlistát vár).
    await page.getByTestId('endpoint-list').getByText(`hook-${run}`).click();
    await page.locator('[data-testid^="delete-"]').first().click();
    await page.getByTestId('delete-confirm').click();
    await expect(page.getByTestId('endpoint-list').getByText(`hook-${run}`)).toHaveCount(0);
});

test('napló: Message-ID szűrő, idővonal, CSV-export (maszkolt címzettel)', async ({ page }) => {
    await page.goto('/dev/seed-logs');
    await page.goto('/logs?recipient=demo%40example.test');

    const firstId = (await page.getByTestId('log-table').locator('[data-message-id]').first().getAttribute('data-message-id')) ?? '';
    expect(firstId).toMatch(/^[0-9a-f-]{36}$/);

    await page.goto('/logs');
    await page.getByTestId('filter-message-id').fill(`<${firstId}@mail.example.test>`);
    await page.getByTestId('log-search').click();
    await expect(page.getByTestId('log-table').locator('tbody tr')).toHaveCount(1);

    await page.getByTestId('log-trace').click();
    await expect(page.getByTestId('trace-timeline')).toBeVisible();
    await expect(page.getByTestId('trace-timeline').locator('[data-kind="delivered"]')).toHaveCount(1);
    await expect(page.getByTestId('trace-timeline').locator('[data-kind="clicked"]').first()).toBeVisible();

    await page.goto('/logs?recipient=demo%40example.test');
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('log-export').click()]);
    expect(download.suggestedFilename()).toMatch(/^ventmail-log-.*\.csv$/);

    const path = await download.path();
    const fs = await import('node:fs');
    const csv = fs.readFileSync(path, 'utf8');
    expect(csv.split('\n')[0]).toContain('message_id');
    expect(csv).toContain('d***@example.test');
    expect(csv).not.toContain('demo@example.test');
});

test('suppression: külön listák, a panasz nem törölhető', async ({ page }) => {
    await page.goto('/suppressions');

    await page.getByTestId('suppression-new').click();
    await page.getByTestId('suppression-email').fill(`panasz-${run}@pelda.hu`);
    await page.getByTestId('suppression-reason').locator('select').selectOption('complaint');
    await page.getByTestId('suppression-submit').click();

    await expect(page).toHaveURL(/list=complaints/);
    await expect(page.getByTestId('list-note-complaints')).toBeVisible();
    await expect(page.getByTestId(`locked-panasz-${run}@pelda.hu`)).toBeVisible();
    await expect(page.getByTestId(`remove-panasz-${run}@pelda.hu`)).toHaveCount(0);

    // A fülek között váltva a lista a szerveren szűrődik.
    await page.getByRole('tab').nth(3).click();
    await expect(page).toHaveURL(/list=manual/);
    await expect(page.getByTestId('suppression-tabs')).not.toContainText(`panasz-${run}@pelda.hu`);
});

test('statisztika: domainenként és időben, grafikonnal', async ({ page }) => {
    await page.goto('/dev/seed-logs');
    await page.goto('/statistics');

    await expect(page.getByTestId('stats-tiles')).toBeVisible();
    await expect(page.getByTestId('stats-domains')).toBeVisible();

    await page.getByTestId('stats-range').getByRole('radio').first().click();
    await expect(page).toHaveURL(/days=7/);

    // A grafikon (ha van adat) canvas-ra rajzol; üres időszaknál magyarázó szöveg.
    await expect(page.getByTestId('stats-chart').or(page.getByTestId('stats-empty'))).toBeVisible();
});
