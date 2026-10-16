import { expect, test, type Locator, type Page } from '@playwright/test';
import { seedStudio } from './support/studio';

/*
 * v1.1 · 6. MODUL — a JOURNEY VÁSZON fő folyamata a böngészőben:
 * vázlat létrehozása → trigger beállítása → e-mail küldés (HÚZÁSSAL a palettáról) + várakozás +
 * kilépés (kattintással) → élek (HÚZÁSSAL a kimenetről a bemenetre, és a panel választóival) →
 * validálás (előbb hibás, utána tiszta) → vázlat mentése → újratöltés után is ott van →
 * teszt-futtatás VALÓDI KÜLDÉS NÉLKÜL.
 *
 * A böngésző-suite küldési módja ZÁRT (SENDING_LIVE / marketing-kapcsoló alapból ki), és a
 * teszt-futtatás amúgy sem küld: a levél-lépés kimenete „kimenne" vagy „a kapu megállítaná",
 * a valódi küldések száma 0.
 */
const run = `j${Date.now().toString(36)}`;

async function kindId(page: Page, kind: string): Promise<string> {
    const testid = await page.locator(`[data-kind="${kind}"]`).first().getAttribute('data-testid');

    return (testid ?? '').replace('jnode-', '');
}

async function center(locator: Locator): Promise<{ x: number; y: number }> {
    const box = await locator.boundingBox();

    if (!box) throw new Error('nincs doboz');

    return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
}

/** Él húzása: a forrás-csomópont kimenetéről a cél bemenetére (egérrel, lépésenként). */
async function dragEdge(page: Page, source: string, handle: string, target: string): Promise<void> {
    const from = await center(page.getByTestId(`handle-${source}-${handle}`));
    const to = await center(page.locator(`[data-testid="jnode-${target}"] .vm-jn__handle--in`));

    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move((from.x + to.x) / 2, (from.y + to.y) / 2, { steps: 8 });
    await page.mouse.move(to.x, to.y, { steps: 8 });
    await page.mouse.up();
}

test('journey-vászon: vázlat összerakása, validálás, mentés és teszt-futtatás küldés nélkül', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    const seed = await seedStudio(page, run);

    // 1. ÚJ VÁZLAT — a választott triggerrel indul.
    await page.goto('/journeys');
    await page.getByTestId('journey-name').fill(`E2E vászon ${run}`);
    await page.getByTestId('journey-trigger').locator('xpath=descendant-or-self::select').selectOption('api_event');
    await page.getByTestId('journey-submit').click();
    await expect(page).toHaveURL(/\/journeys\/[0-9a-f-]{36}$/);

    const canvas = page.getByTestId('journey-canvas');

    await expect(canvas).toHaveAttribute('data-status', 'draft');
    await expect(page.getByTestId('jnode-trigger')).toBeVisible();

    // A még bekötetlen triggerek „hamarosan" jelzéssel, választhatatlanul (review REV-S1).
    await expect(page.getByTestId('palette-soon-tag_removed')).toBeVisible();
    await expect(page.getByTestId('palette-tag_removed')).toBeDisabled();

    // D284: a „Hamarosan" jelvény NEM nyomhatja össze a trigger nevét — a név szóhatáron tördel
    // (nem szó közepén: „Címke hozzáa / dva"), és a jelvény nem fedi a nevet.
    for (const type of ['tag_added', 'tag_removed', 'field_changed']) {
        const item = page.getByTestId(`palette-${type}`);
        const metrics = await item.evaluate((button) => {
            const label = button.querySelector('.vm-jpal__label') as HTMLElement;
            const badge = button.querySelector('.vm-jpal__soon') as HTMLElement;
            const range = document.createRange();
            range.selectNodeContents(label);
            const lines = new Set([...range.getClientRects()].map((rect) => Math.round(rect.top))).size;
            const words = (label.textContent ?? '').trim().split(/\s+/).length;
            const a = label.getBoundingClientRect();
            const b = badge.getBoundingClientRect();
            const overlap = a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;

            return { lines, words, overlap };
        });

        expect(metrics.lines, `${type}: a név szó közepén tört`).toBeLessThanOrEqual(metrics.words);
        expect(metrics.overlap, `${type}: a jelvény fedi a nevet`).toBe(false);
    }

    // 2. A TRIGGER kijelölése BILLENTYŰZETTEL (review REV-S7) — a panel megnyílik.
    await page.locator('.vue-flow__node[data-id="trigger"]').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('cfg-event')).toBeVisible();
    await page.getByTestId('cfg-event').fill('signup');

    // 3. E-MAIL KÜLDÉSE — húzással a palettáról a vászonra.
    const trigger = await page.getByTestId('jnode-trigger').boundingBox();
    const flow = await page.getByTestId('journey-flow').boundingBox();

    await page.getByTestId('palette-send_email').dragTo(page.getByTestId('journey-flow'), {
        targetPosition: { x: (trigger?.x ?? 0) - (flow?.x ?? 0) + 300, y: (trigger?.y ?? 0) - (flow?.y ?? 0) + 10 },
    });
    await expect(page.locator('[data-kind="send_email"]')).toHaveCount(1);
    const send = await kindId(page, 'send_email');

    // Az új csomópont kijelölve nyílik: a panel már az övé.
    await expect(page.getByTestId('cfg-template')).toBeVisible();
    await page.getByTestId('cfg-template').locator('xpath=descendant-or-self::select').selectOption(seed.templates.compliant.uuid);
    await page.getByTestId('cfg-from').fill('hirlevel@pelda.hu');

    // 4. VÁRAKOZÁS és KILÉPÉS — kattintással (billentyűzettel is elérhető út).
    await page.getByTestId('palette-wait').click();
    await expect(page.locator('[data-kind="wait"]')).toHaveCount(1);
    const wait = await kindId(page, 'wait');

    await page.getByTestId('cfg-wait-amount').fill('2');
    await page.getByTestId('cfg-wait-unit').locator('xpath=descendant-or-self::select').selectOption('days');

    await page.getByTestId('palette-exit').click();
    await expect(page.locator('[data-kind="exit"]')).toHaveCount(1);
    const exit = await kindId(page, 'exit');


    // 5. VALIDÁLÁS bekötés ELŐTT: árva csomópontok → hiba.
    await page.getByTestId('journey-validate').click();
    await expect(page.getByTestId('journey-issues')).not.toHaveAttribute('data-errors', '0');
    await expect(page.getByTestId('issue-orphan_node').first()).toBeVisible();

    // 6. ÉLEK — a trigger kimenetét HÚZÁSSAL kötjük az e-mailre…
    await page.getByTestId('zoom-fit').click();
    await page.waitForTimeout(400);
    await dragEdge(page, 'trigger', 'out', send);
    await expect(page.locator('.vue-flow__edge')).toHaveCount(1);

    // …a többit a panel „Kimenetek" választójával.
    await page.getByTestId(`jnode-${send}`).click();
    await page.getByTestId('cfg-connect-out').locator('xpath=descendant-or-self::select').selectOption(wait);
    await page.getByTestId(`jnode-${wait}`).click();
    await page.getByTestId('cfg-connect-out').locator('xpath=descendant-or-self::select').selectOption(exit);
    await expect(page.locator('.vue-flow__edge')).toHaveCount(3);

    // 7. VALIDÁLÁS: tiszta.
    await page.getByTestId('journey-validate').click();
    await expect(page.getByTestId('journey-issues')).toHaveAttribute('data-errors', '0');

    // 8. VÁZLAT MENTÉSE — és újratöltés után is ott van.
    await page.getByTestId('journey-save').click();
    await expect(canvas).toHaveAttribute('data-save', 'saved');

    await page.reload();
    await expect(page.locator('.vm-jn')).toHaveCount(4);
    await expect(page.locator('.vue-flow__edge')).toHaveCount(3);
    await expect(page.getByTestId('journey-canvas')).toHaveAttribute('data-status', 'draft');
    await expect(page.getByTestId('journey-issues')).toHaveAttribute('data-errors', '0');

    // 9. TESZT-FUTTATÁS a megerősített kontakttal — valódi küldés NÉLKÜL.
    await page.getByTestId('test-run-email').fill(seed.emails.confirmed);
    await page.getByTestId('test-run').click();

    const panel = page.getByTestId('test-run-panel');

    await expect(panel).toBeVisible();
    await expect(page.getByTestId('test-step-0')).toHaveAttribute('data-outcome', 'entered');
    await expect(page.getByTestId('test-step-1')).toHaveAttribute('data-outcome', /^(would_send|blocked)$/);
    await expect(page.getByTestId('test-step-2')).toHaveAttribute('data-outcome', 'waited');
    await expect(page.getByTestId('test-step-3')).toHaveAttribute('data-outcome', 'exit');
    await expect(page.getByTestId('test-run-summary')).toContainText('0');

    // A journey VÁZLAT maradt: a teszt-futtatás nem aktivál és nem publikál.
    await expect(page.getByTestId('journey-canvas')).toHaveAttribute('data-status', 'draft');
});
