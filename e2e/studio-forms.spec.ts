import { expect, test } from '@playwright/test';
import { seedStudio } from './support/studio';

/*
 * v1.1 · 5. modul — ŰRLAP-ÉPÍTŐ → hosztolt beküldés → FÜGGŐ (double opt-in) feliratkozás, és a
 * PREFERENCE CENTER globális leiratkozása.
 *
 * A hosztolt oldal szkript nélküli (szigorú CSP): a time-trap ideje a szerver renderelési
 * tokenjéből jön, ezért a teszt valóban kivárja a beállított minimumot.
 */
const run = `f${Date.now().toString(36)}`;

test('űrlap építése, hosztolt beküldés → a kontakt függő, megerősítésre vár', async ({ page }) => {
    await page.goto('/forms');

    const name = `E2E építő ${run}`;

    await page.getByTestId('form-name').fill(name);
    await page.getByTestId('form-min-seconds').fill('1');
    await page.getByTestId('form-consent-text').fill('Hozzájárulok a kávés hírlevélhez.');
    await expect(page.getByTestId('form-doi')).toBeVisible();
    await page.getByTestId('builder-required-first_name').click();
    await page.getByTestId('builder-new-field').locator('select').selectOption('last_name');
    await page.getByTestId('builder-add-field').click();
    await expect(page.getByTestId('builder-field-last_name')).toBeVisible();
    await page.getByTestId('form-page-title').fill(`Kávés hírek ${run}`);
    await page.getByTestId('form-success-title').fill('Már majdnem kész!');
    await expect(page.getByTestId('form-preview')).toContainText(`Kávés hírek ${run}`);
    await page.getByTestId('form-submit').click();

    const row = page.locator('[data-testid^="form-"]', { hasText: name }).first();
    await expect(row).toBeVisible();

    await row.locator('[data-testid^="snippet-"]').click();
    await row.locator('[data-testid^="embed-tab-hosted-"]').click();
    const hostedUrl = (await row.locator('[data-testid^="hosted-url-"]').innerText()).trim();

    expect(hostedUrl).toMatch(/\/f\/[A-Za-z0-9-]+$/);

    const email = `urlap-${run}@pelda.hu`;

    await page.goto(new URL(hostedUrl).pathname);
    await expect(page.locator('h1')).toHaveText(`Kávés hírek ${run}`);
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="first_name"]').fill('Anna');
    await page.locator('input[name="last_name"]').fill('Teszt');
    await page.locator('input[name="consent"]').check();
    // A time-trap (1 mp) — a renderelési token idejéhez képest.
    await page.waitForTimeout(1500);
    await page.getByTestId('hosted-submit').click();
    await expect(page.getByTestId('hosted-thanks')).toHaveText('Már majdnem kész!');

    await page.goto(`/audience?search=${encodeURIComponent(email)}`);
    const contact = page.getByTestId('contacts-table');
    await expect(contact).toContainText(email);
    // Állapot és consent is `pending`: a megerősítő link előtt marketing nem mehet ki.
    await expect(contact.locator('tbody tr').first().locator('[data-status]')).toHaveAttribute('data-status', 'pending');
    await expect(contact.locator('tbody tr').first().locator('[data-consent]')).toHaveAttribute('data-consent', 'pending');
});

test('a preference center globális leiratkozása leiratkoztat (suppression + visszavont consent)', async ({ page }) => {
    const seed = await seedStudio(page, run);

    await page.goto(seed.preferenceUrl);
    await expect(page.getByTestId('preferences-form')).toBeVisible();
    await expect(page.locator('input[name="lists[]"]')).toHaveCount(1);

    await page.getByTestId('preferences-unsubscribe-all').click();
    await expect(page.getByTestId('preferences-saved')).toBeVisible();

    await page.goto(`/audience?search=${encodeURIComponent(seed.emails.confirmed)}`);
    await expect(page.getByTestId('contacts-table').locator('tbody tr').first().locator('[data-status]')).toHaveAttribute('data-status', 'unsubscribed');
});
