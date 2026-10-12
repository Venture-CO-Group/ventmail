import { expect, test, type Page } from '@playwright/test';
import { seedStudio } from './support/studio';

/*
 * v1.1 · 5. modul — KAMPÁNY CHECKLIST a böngészőben.
 *
 * A teljes flow: címzettek → feladó (csak hitelesített domain) → tárgy merge-taggel →
 * tartalom (előbb HELYŐRZŐ postai címes sablon: a küldés gomb letiltva, a szerver-ellenőrzés
 * piros; utána valódi címes: zöld) → ütemezés (most) → küldés. A böngésző-suite küldési
 * módja ZÁRT (SENDING_LIVE / marketing-kapcsoló alapból ki, ezekhez nem nyúlunk), tehát a
 * PreSendGate a küldéskor elutasít — a spec a kimenet OKKÓDJÁT méri, nem azt, hogy „kiment".
 */
const run = `c${Date.now().toString(36)}`;

/** Szakasz mentése: a PUT válaszát (és az Inertia újrarajzolását) MEGVÁRJUK — különben a
 *  következő kattintás a még repülő mentés válaszával íródna felül. */
async function save(page: Page, section: string): Promise<void> {
    await page.getByTestId(`section-save-${section}`).click();
    // A mentés akkor kész, ha a szerver válasza visszaért, és az űrlap újra „mentett".
    const status = page.getByTestId('campaign-status');

    await expect(status).toHaveAttribute('data-saving', '0', { timeout: 15_000 });
    await expect(status).toHaveAttribute('data-dirty', '0');
}

test('checklist-flow: a gomb csak zöld ellenőrzéseknél él, és a kapu okkóddal dönt', async ({ page }) => {
    const seed = await seedStudio(page, run);

    await page.goto('/campaigns');
    await page.getByTestId('campaign-new').click();
    await page.getByTestId('campaign-name').fill(`E2E checklist ${run}`);
    await page.getByTestId('campaign-submit').click();
    await expect(page).toHaveURL(/\/campaigns\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('campaign-status')).toHaveAttribute('data-status', 'draft');
    await expect(page.getByTestId('campaign-send')).toBeDisabled();

    // 1. CÍMZETTEK — a seedelt lista (3 kontakt: megerősített, függő, hozzájárulás nélküli).
    await expect(page.getByTestId('section-recipients')).toHaveAttribute('data-done', 'false');
    await page.getByTestId(`target-list-${seed.list.uuid}`).click();
    await save(page, 'recipients');
    await expect(page.getByTestId('section-recipients')).toHaveAttribute('data-done', 'true');

    // 2. FELADÓ — a domain választó CSAK hitelesített domaint kínál.
    await page.getByTestId('section-toggle-sender').click();
    await page.getByTestId('edit-from-name').fill('E2E Studio');
    await page.getByTestId('edit-from').fill('hirlevel');
    await page.getByTestId('edit-from-domain').locator('select').selectOption('pelda.hu');
    await save(page, 'sender');
    await expect(page.getByTestId('section-sender')).toHaveAttribute('data-done', 'true');
    await expect(page.getByTestId('check-domain')).toHaveAttribute('data-ok', 'true');

    // 3. TÁRGY — merge-tag a választóból.
    await page.getByTestId('section-toggle-subject').click();
    await page.getByTestId('edit-subject').fill('Őszi újdonságok,');
    await page.getByTestId('subject-merge-tag').click();
    await page.getByTestId('merge-tag-contact.first_name').click();
    await expect(page.getByTestId('edit-subject')).toHaveValue(/\{\{ contact\.first_name/);
    await page.getByTestId('edit-preheader').fill('Friss kávé és péntek esti előzetes');
    await save(page, 'subject');
    await expect(page.getByTestId('section-subject')).toHaveAttribute('data-done', 'true');

    // 4. TARTALOM — HELYŐRZŐ postai cím: a szerver-ellenőrzés piros, a gomb letiltva.
    await page.getByTestId('section-toggle-content').click();
    await page.getByTestId('edit-template').locator('select').selectOption(seed.templates.placeholder.uuid);
    await save(page, 'content');
    await expect(page.getByTestId('check-compliance')).toHaveAttribute('data-ok', 'false');
    await expect(page.getByTestId('section-content')).toHaveAttribute('data-done', 'false');

    // 5. ÜTEMEZÉS — most.
    await page.getByTestId('section-toggle-schedule').click();
    await page.getByTestId('schedule-now').click();
    await save(page, 'schedule');
    await expect(page.getByTestId('section-schedule')).toHaveAttribute('data-done', 'true');

    // Minden más kész, de a lábléc helyőrző címe miatt NEM küldhető.
    await expect(page.getByTestId('campaign-send')).toBeDisabled();
    await expect(page.getByTestId('check-consent')).toHaveAttribute('data-ok', 'true');

    // Valódi postai címes sablon → zöld.
    await page.getByTestId('section-toggle-content').click();
    await page.getByTestId('edit-template').locator('select').selectOption(seed.templates.compliant.uuid);
    await save(page, 'content');
    await expect(page.getByTestId('check-compliance')).toHaveAttribute('data-ok', 'true');
    await expect(page.getByTestId('checklist-progress')).toContainText('6');
    await expect(page.getByTestId('campaign-send')).toBeEnabled();

    // KÜLDÉS — a PreSendGate a zárt küldési mód miatt elutasít; a kimenet az okkódot mutatja.
    await page.getByTestId('campaign-send').click();
    await expect(page.getByTestId('flash-success')).toBeVisible();
    await expect(page.getByTestId('campaign-outcomes')).toBeVisible();

    const skipped = page.getByTestId('outcome-skipped').first();

    await expect(skipped).toBeVisible();
    expect(await skipped.getAttribute('data-reason')).toMatch(/^(CHANNEL_DISABLED|SENDING_RESTRICTED)$/);
    await expect(page.getByTestId('outcome-sent')).toHaveCount(0);

    // A listán a kampány SIKERTELEN (lezárt, de egyetlen levél sem ment ki).
    await page.goto(`/campaigns?search=${encodeURIComponent(`E2E checklist ${run}`)}`);
    await expect(page.locator('[data-testid^="campaign-row-status-"]').first()).toHaveAttribute('data-status', 'failed');
});

test('a kampánylista szűr állapot szerint', async ({ page }) => {
    await page.goto('/campaigns');
    await page.getByTestId('campaign-filter-draft').click();
    await expect(page).toHaveURL(/status=draft/);
    await expect(page.getByTestId('campaign-filter-draft')).toHaveAttribute('aria-pressed', 'true');

    const statuses = await page.locator('[data-testid^="campaign-row-status-"]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-status')));

    expect(statuses.every((status) => status === 'draft')).toBe(true);
});
