import { expect, test, type Page } from '@playwright/test';

/*
 * v1.1 · 4. MODUL — a drag-and-drop sablonszerkesztő fő folyamata a böngészőben:
 * indulósablonból létrehozás → blokk HÚZÁSA a vászonra → szöveg szerkesztése helyben →
 * automatikus mentés → verzió-visszaállítás → optimalizálás panel → teszt-küldés a kapun át.
 *
 * A mérés a SZERVER állapotán fut (újratöltés után is ott van-e), nem csak a DOM-on: a
 * szerkesztő igazsága a mentett blokk-JSON.
 */
test.describe.configure({ mode: 'serial' });

const run = Date.now().toString(36);
const name = `E2E hírlevél ${run}`;
let editorUrl = '';

async function waitSaved(page: Page): Promise<void> {
    await expect(page.getByTestId('save-status')).toHaveAttribute('data-state', 'saved', { timeout: 15_000 });
}

async function countBlocks(page: Page, type: string): Promise<number> {
    return page.locator(`[data-testid="editor-canvas"] [data-type="${type}"]`).count();
}

test.beforeAll(async ({ browser }) => {
    // A teszt-küldéshez kell egy hitelesített domain a dev tenantban (idempotens seed).
    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    await page.goto('/dev/seed-logs');
    await context.close();
});

test('a könyvtár a hat indulósablont mutatja, és indulósablonból új sablon nyílik a szerkesztőben', async ({ page }) => {
    await page.goto('/templates');

    for (const key of ['newsletter', 'promotion', 'product', 'event', 'thanks', 'reengage']) {
        await expect(page.getByTestId(`starter-${key}`)).toBeVisible();
    }

    await page.getByTestId('starter-newsletter').click();
    await page.getByTestId('template-name').fill(name);
    await page.getByTestId('template-submit').click();

    await expect(page).toHaveURL(/\/templates\/[0-9a-f-]{36}$/);
    editorUrl = new URL(page.url()).pathname;

    await expect(page.getByTestId('editor-name')).toHaveValue(name);
    await expect(page.getByTestId('editor-canvas')).toBeVisible();
    // Az indulósablon tartalma: fejléc, hero, cikkek, lábléc — a kötelező lábléc is benne van.
    expect(await countBlocks(page, 'footer')).toBe(1);
    expect(await countBlocks(page, 'image')).toBeGreaterThanOrEqual(2);
    await expect(page.getByTestId('save-status')).toHaveAttribute('data-state', 'saved');
});

test('blokk HÚZÁSA a palettáról a vászonra, beszúrási jelzővel, és az autosave elmenti', async ({ page }) => {
    await page.goto(editorUrl);

    const before = await countBlocks(page, 'button');

    // A cél: a hero címsora — a felső felére ejtve ELÉ kerül.
    const target = page.getByTestId('block-1-0-0');
    await page.getByTestId('palette-button').dragTo(target, { targetPosition: { x: 20, y: 4 } });

    await expect.poll(() => countBlocks(page, 'button')).toBe(before + 1);
    await expect(page.getByTestId('block-1-0-0')).toHaveAttribute('data-type', 'button');
    await waitSaved(page);

    await page.reload();
    await expect(page.getByTestId('block-1-0-0')).toHaveAttribute('data-type', 'button');
});

test('billentyűzetes alternatíva: kattintással beszúr, fel/le mozgat, duplikál, töröl', async ({ page }) => {
    await page.goto(editorUrl);

    await page.getByTestId('block-1-0-0').click();
    await page.getByTestId('down-1-0-0').click();
    await expect(page.getByTestId('block-1-0-1')).toHaveAttribute('data-type', 'button');

    await page.getByTestId('duplicate-1-0-1').click();
    await expect(page.getByTestId('block-1-0-2')).toHaveAttribute('data-type', 'button');

    await page.getByTestId('delete-1-0-2').click();
    await expect(page.getByTestId('block-1-0-2')).not.toHaveAttribute('data-type', 'button');

    // KATTINTÁS a palettán: a kijelölés UTÁN kerül be (a húzás billentyűzetes megfelelője).
    await page.getByTestId('block-1-0-1').click();
    await page.getByTestId('palette-divider').click();
    await expect(page.getByTestId('block-1-0-2')).toHaveAttribute('data-type', 'divider');

    await page.getByTestId('editor-undo').click();
    await expect(page.getByTestId('block-1-0-2')).not.toHaveAttribute('data-type', 'divider');
    await waitSaved(page);
});

test('a szöveg HELYBEN szerkeszthető (félkövér, merge-tag), és a mentés után is ott van', async ({ page }) => {
    await page.goto(editorUrl);

    const paragraph = page.getByTestId('rich-1-0-2');
    await paragraph.click();
    await expect(page.getByTestId('rich-toolbar')).toBeVisible();

    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.type(`Szerkesztett bekezdés ${run}`);
    await page.keyboard.press('ControlOrMeta+a');
    await page.getByTestId('rich-bold').click();

    // Merge-tag a saját szintaxisunkkal, alapértékkel.
    await page.keyboard.press('End');
    await page.getByTestId('rich-merge').click();
    await page.getByTestId('merge-tag-contact.first_name').click();

    await expect(paragraph).toContainText('{{ contact.first_name ||');
    // A félkövér a böngészőtől függően `<b>` vagy `font-weight` stílus — mindkettő átmegy a tisztításon.
    await expect(paragraph.locator('b, strong, [style*="bold"]')).toContainText('Szerkesztett bekezdés');
    await waitSaved(page);

    await page.reload();
    await expect(page.getByTestId('rich-1-0-2')).toContainText(`Szerkesztett bekezdés ${run}`);
    await expect(page.getByTestId('rich-1-0-2')).toContainText('{{ contact.first_name ||');
});

test('a VERZIÓ visszaállítható — és a visszaállítás új verziót ír', async ({ page }) => {
    await page.goto(editorUrl);

    // KÉZI mentés: új verzió a mostani állapotról.
    await page.keyboard.press('ControlOrMeta+s');
    await waitSaved(page);

    await page.getByTestId('editor-versions').click();
    const drawer = page.getByTestId('versions-drawer');
    await expect(drawer.getByTestId('version-1')).toBeVisible();

    const versionsBefore = await drawer.locator('[data-testid^="version-"]').count();
    expect(versionsBefore).toBeGreaterThanOrEqual(2);

    // Az ELSŐ verzió (a létrehozáskori) visszaállítása: a beszúrt gomb és a szerkesztett szöveg eltűnik.
    await drawer.getByTestId('restore-1').click();
    await expect(page.getByTestId('editor-notice')).toBeVisible();
    await expect(drawer.locator('[data-testid^="version-"]')).toHaveCount(versionsBefore + 1);

    await page.keyboard.press('Escape');
    await expect(page.getByTestId('block-1-0-0')).toHaveAttribute('data-type', 'heading');
    await expect(page.getByTestId('editor-canvas')).not.toContainText(`Szerkesztett bekezdés ${run}`);

    await page.reload();
    await expect(page.getByTestId('block-1-0-0')).toHaveAttribute('data-type', 'heading');
});

test('az OPTIMALIZÁLÁS panel: linkek, képek, súly, spam, kötelező elemek, eszköz-nézetek', async ({ page }) => {
    await page.goto(editorUrl);

    await page.getByTestId('rail-optimize').click();
    const panel = page.getByTestId('optimize-panel');

    await expect(panel.getByTestId('optimize-links')).toBeVisible();
    await expect(panel.getByTestId('optimize-images')).toBeVisible();
    await expect(panel.getByTestId('optimize-weight')).toContainText('102');
    await expect(panel.getByTestId('optimize-spam')).toBeVisible();
    await expect(panel.getByTestId('optimize-compliance')).toBeVisible();

    // A mobil és a sötét nézet a vásznon.
    await panel.getByTestId('optimize-device-mobile').click();
    await expect(page.getByTestId('editor-canvas')).toHaveClass(/vm-ed-canvas--mobile/);
    await panel.getByTestId('optimize-device-dark').click();
    await expect(page.getByTestId('editor-canvas')).toHaveClass(/vm-ed-canvas--dark/);

    // UTM hozzáadása: a hiányzó UTM-ek száma nullára esik.
    await panel.getByTestId('optimize-add-utm').click();
    await expect(panel.getByTestId('optimize-links')).toContainText(/, 0 /);
});

test('a TESZT-KÜLDÉS a PreSendGate-en megy — a válasz a kapu döntése', async ({ page }) => {
    await page.goto(editorUrl);

    await page.getByTestId('rail-optimize').click();
    const form = page.getByTestId('optimize-panel').getByTestId('test-send');

    await expect(form.locator('[data-testid^="test-recipient-"]').first()).toBeVisible();
    await form.getByTestId('test-from-local').fill('hello');
    await form.getByTestId('test-send-submit').click();

    // A dev-környezet küldési módja (seed-mód / fake driver) dönt: vagy elment, vagy a kapu
    // OKÁT kapjuk vissza — mindkét esetben a levél a kapun ment át, nem driver-hívással.
    const result = form.getByTestId('test-send-result');
    await expect(result).toBeVisible({ timeout: 15_000 });

    // A dev-környezet küldési módja dönt: vagy kiment (data-ok=true), vagy a KAPU konkrét
    // okát kapjuk vissza (seed-mód / kikapcsolt marketing — D52: a marketing teszt a
    // campaign csatornán megy). Validációs hiba vagy ismeretlen ok NEM elfogadható.
    const ok = await result.getAttribute('data-ok');
    const reason = await result.getAttribute('data-reason');
    expect(ok === 'true' || ['SENDING_RESTRICTED', 'CHANNEL_DISABLED'].includes(reason ?? ''), `ok=${ok} reason=${reason}`).toBe(true);
});

test('az ELŐNÉZET a szerver fordítójából jön (HTML és plaintext)', async ({ page }) => {
    await page.goto(editorUrl);

    await page.getByTestId('editor-preview').click();
    const modal = page.getByTestId('preview-modal');

    await expect(modal.getByTestId('preview-frame')).toBeVisible();
    await expect(modal.getByTestId('preview-frame')).toHaveAttribute('srcdoc', /<!doctype html>/);

    await modal.getByTestId('preview-mode-text').click();
    await expect(modal.getByTestId('preview-text')).toContainText('{{ unsubscribe_url }}');
});

test('a kötelező lábléc nélkül a mentés elutasít — a hiba látszik, a tárolt sablon változatlan', async ({ page }) => {
    await page.goto(editorUrl);

    const footer = page.locator('[data-testid="editor-canvas"] [data-type="footer"]');
    await footer.click();
    await page.keyboard.press('Delete');

    await expect(page.getByTestId('save-status')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
    await expect(page.getByTestId('save-error')).toBeVisible();

    // A SZERVER állapota: újratöltés után a lábléc ott van — a hibás mentés nem írt felül.
    page.once('dialog', (dialog) => void dialog.accept());
    await page.reload();
    await expect(page.locator('[data-testid="editor-canvas"] [data-type="footer"]')).toHaveCount(1);
    await expect(page.getByTestId('save-status')).toHaveAttribute('data-state', 'saved');
});

test('a könyvtárban a sablon a saját sablonok között van, és mentett blokk is létrehozható', async ({ page }) => {
    await page.goto(editorUrl);

    await page.getByTestId('block-1-0-0').click();
    await page.getByTestId('save-block-1-0-0').click();
    await page.getByTestId('name-modal-input').fill(`E2E blokk ${run}`);
    await page.getByTestId('name-modal-submit').click();
    await expect(page.getByTestId('editor-notice')).toBeVisible();

    await page.getByTestId('editor-save-exit').click();
    await expect(page).toHaveURL(/\/templates$/);

    await expect(page.getByTestId('library-own')).toContainText(name);
    await expect(page.getByTestId('library-blocks')).toContainText(`E2E blokk ${run}`);
});
