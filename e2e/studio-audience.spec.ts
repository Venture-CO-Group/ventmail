import { expect, test, type Page } from '@playwright/test';
import { seedStudio, type StudioSeed } from './support/studio';

/*
 * v1.1 · 5. modul — SZEGMENSÉPÍTŐ és TÖMEGES CÍMKÉZÉS a böngészőben.
 *
 * A kiinduló adatot a dev-only `/dev/seed-studio` adja (futásonként egyedi kontaktok: egy
 * megerősített, egy függő és egy hozzájárulás nélküli). A spec a SZÁMOT méri: az élő
 * előnézetnek a szerver motorjának eredményét kell mutatnia, és a mentett szegmens / a
 * címke-darabszám ugyanazt adja vissza.
 */
const run = `a${Date.now().toString(36)}`;

async function seed(page: Page): Promise<StudioSeed> {
    return seedStudio(page, run);
}

test('a szegmensépítő ÉS/VAGY csoportokkal élő számot ad, és a mentett szegmens ugyanazt tartja', async ({ page }) => {
    await page.goto('/dashboard');
    await seed(page);

    await page.goto('/audience/segments/new');
    await page.getByTestId('segment-name').fill(`E2E szegmens ${run}`);

    // 1. feltétel: az e-mail tartalmazza a futás azonosítóját → mindhárom seedelt kontakt.
    await page.getByTestId('segment-add-rule-root').click();
    await page.getByTestId('rule-field-0').locator('select').selectOption('email');
    await page.getByTestId('rule-op-0').locator('select').selectOption('contains');
    await page.getByTestId('rule-value-0').fill(`studio-${run}-`);

    await expect(page.getByTestId('segment-count')).toHaveText('3', { timeout: 10_000 });

    // 2. egy beágyazott VAGY-csoport: megerősített VAGY függő consent → a „nincs" kiesik.
    await page.getByTestId('segment-add-group-root').click();
    await page.getByTestId('rule-field-1-0').locator('select').selectOption('consent');
    await page.getByTestId('rule-value-1-0').locator('select').selectOption('confirmed');
    await page.getByTestId('segment-group-1').getByTestId('segment-add-rule-1').click();
    await page.getByTestId('rule-field-1-1').locator('select').selectOption('consent');
    await page.getByTestId('rule-value-1-1').locator('select').selectOption('pending');

    await expect(page.getByTestId('segment-count')).toHaveText('2', { timeout: 10_000 });
    await expect(page.getByTestId('segment-sample')).not.toContainText(`studio-${run}-nincs`);

    // A csoport mód-váltása ÉS-re: egyszerre megerősített és függő senki nem lehet → 0.
    await page.getByTestId('segment-match-1').locator('button').first().click();
    await expect(page.getByTestId('segment-count')).toHaveText('0', { timeout: 10_000 });
    await page.getByTestId('segment-match-1').locator('button').nth(1).click();
    await expect(page.getByTestId('segment-count')).toHaveText('2', { timeout: 10_000 });

    await page.getByTestId('segment-save').click();
    await expect(page).toHaveURL(/\/audience\/segments\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('flash-success')).toBeVisible();

    // A SZEGMENS TALÁLATAINAK tömeges címkézése — egy új címkével, a mentett definíció szerint.
    const segmentTag = `e2e-szegmens-${run}`;

    await page.getByTestId('segment-bulk-tag').locator('select').selectOption('__new__');
    await page.getByTestId('segment-bulk-new-tag').fill(segmentTag);
    await page.getByTestId('segment-bulk-apply').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();
    const segmentUrl = page.url();

    await page.goto('/audience/tags');
    await expect(page.getByTestId(`tag-count-${segmentTag}`)).toContainText('2');
    await page.goto(segmentUrl);

    await page.goto('/audience/segments');
    const row = page.locator('[data-testid^="segment-row-"]', { hasText: `E2E szegmens ${run}` });
    await expect(row).toContainText('2');
});

test('a hibás feltétel a motor üzenetét mutatja, nem csendes nullát', async ({ page }) => {
    await page.goto('/audience/segments/new');
    await page.getByTestId('segment-add-rule-root').click();
    await page.getByTestId('rule-field-0').locator('select').selectOption('engagement');
    await page.getByTestId('rule-value-0').fill('99999');

    await expect(page.getByTestId('segment-error')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('segment-save')).toBeDisabled();
});

test('tömeges címkézés a kontaktlistából, a címke-darabszám a címkék oldalon', async ({ page }) => {
    await page.goto('/dashboard');
    const data = await seed(page);

    await page.goto(`/audience?search=${encodeURIComponent(`studio-${run}-`)}`);

    await page.getByTestId(`contact-select-${data.emails.confirmed}`).click();
    await page.getByTestId(`contact-select-${data.emails.pending}`).click();
    await expect(page.getByTestId('bulk-selected')).toContainText('2');

    const tagName = `e2e-cimke-${run}`;

    await page.getByTestId('bulk-tag').locator('select').selectOption('__new__');
    await page.getByTestId('bulk-new-tag').fill(tagName);
    await page.getByTestId('bulk-apply').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();

    await page.goto('/audience/tags');
    await expect(page.getByTestId(`tag-count-${tagName}`)).toContainText('2');

    // „A keresés minden találatára" levétel → 0.
    await page.goto(`/audience?search=${encodeURIComponent(`studio-${run}-`)}`);
    await page.getByTestId('bulk-all').click();
    await page.getByTestId('bulk-action').locator('select').selectOption('untag');
    await page.getByTestId('bulk-tag').locator('select').selectOption({ label: tagName });
    await page.getByTestId('bulk-apply').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();

    await page.goto('/audience/tags');
    await expect(page.getByTestId(`tag-count-${tagName}`)).toContainText('0');

    // Átnevezés és törlés a címkék oldalon.
    await page.getByTestId(`tag-rename-${tagName}`).click();
    await page.getByTestId('tag-rename-input').fill(`${tagName}-x`);
    await page.getByTestId('tag-rename-save').click();
    await expect(page.getByTestId(`tag-row-${tagName}-x`)).toBeVisible();

    await page.getByTestId(`tag-delete-${tagName}-x`).click();
    await page.getByTestId('tag-delete-confirm').click();
    await expect(page.getByTestId(`tag-row-${tagName}-x`)).toHaveCount(0);
});

test('a közönség-dashboard a szerver aggregátumait mutatja', async ({ page }) => {
    await page.goto('/audience');

    await expect(page.getByTestId('audience-insights')).toBeVisible();
    await expect(page.getByTestId('insights-growth')).toBeVisible();
    await expect(page.getByTestId('insights-sources-manual')).toBeVisible();
    await expect(page.getByTestId('insights-engagement-not_mailed')).toBeVisible();
});
