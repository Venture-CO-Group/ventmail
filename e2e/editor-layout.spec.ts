import { expect, test, type Page } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 7. pont — A SZERKESZTŐ ELRENDEZÉSE (sablon- és kampány-szerkesztő, egy komponens).
 *
 *  - a bal (paletta) és a jobb (beállítások) panel ÖSSZECSUKHATÓ, keskenyebb alapszélességgel;
 *  - a jobb panel alapból REJTETT: elemre kattintva nyílik, a kijelölés megszűnésekor csukódik;
 *  - az előnézet (vászon) kapja a helyet;
 *  - az előnézet MAGASSÁGA nincs korlátozva, nincs belső görgetés: a lap görget, a hosszú
 *    (több képernyős) levél teljes egészében renderelődik.
 */
test.describe.configure({ mode: 'serial' });

const run = Date.now().toString(36);
let editorUrl = '';

async function stageWidth(page: Page): Promise<number> {
    return (await page.getByTestId('editor-stage').boundingBox())!.width;
}

test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json', viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    // A hírlevél-indulósablon több képernyős levél.
    await page.goto('/templates');
    await page.getByTestId('starter-newsletter').click();
    await page.getByTestId('template-name').fill(`E2E elrendezés ${run}`);
    await page.getByTestId('template-submit').click();
    await page.waitForURL(/\/templates\/[0-9a-f-]{36}$/);
    editorUrl = new URL(page.url()).pathname;
    await context.close();
});

test.use({ viewport: { width: 1440, height: 900 } });

test('alapállapot: a jobb panel rejtett, a bal panel keskeny, az előnézet kapja a helyet', async ({ page }) => {
    await page.goto(editorUrl);
    await expect(page.getByTestId('editor-canvas')).toBeVisible();

    await expect(page.getByTestId('editor-props')).toHaveCount(0);
    await expect(page.getByTestId('editor-palette')).toBeVisible();

    const palette = (await page.getByTestId('editor-palette').boundingBox())!.width;
    const stage = await stageWidth(page);

    // A paletta legfeljebb a nézet ötöde, az előnézet legalább a 70%-a.
    expect(palette).toBeLessThanOrEqual(1440 / 5);
    expect(stage).toBeGreaterThanOrEqual(1440 * 0.7);

    // A levél a SAJÁT szélességén (a stílus `width`-je) jelenik meg, nincs összenyomva.
    const canvas = (await page.getByTestId('editor-canvas').boundingBox())!.width;
    const width = Number(await page.getByTestId('editor-canvas').getAttribute('data-width'));

    expect(Math.abs(canvas - width)).toBeLessThanOrEqual(1);
});

test('a bal panel összecsukható és kinyitható; a felszabaduló hely az előnézeté', async ({ page }) => {
    await page.goto(editorUrl);
    await expect(page.getByTestId('editor-palette')).toBeVisible();

    const before = await stageWidth(page);

    await page.getByTestId('palette-collapse').click();
    await expect(page.getByTestId('editor-palette')).toHaveCount(0);
    expect(await stageWidth(page)).toBeGreaterThan(before);

    await page.getByTestId('palette-expand').click();
    await expect(page.getByTestId('editor-palette')).toBeVisible();
    expect(Math.abs((await stageWidth(page)) - before)).toBeLessThanOrEqual(1);

    // A sín gombja is kinyitja (és az összecsukott állapotban az aktív fület választja).
    await page.getByTestId('palette-collapse').click();
    await page.getByTestId('rail-styles').click();
    await expect(page.getByTestId('editor-palette')).toBeVisible();
});

test('elemre kattintva megjelenik a jobb panel, máshová kattintva eltűnik; összecsukható', async ({ page }) => {
    await page.goto(editorUrl);
    await expect(page.getByTestId('editor-canvas')).toBeVisible();

    await page.getByTestId('block-1-0-0').click();
    await expect(page.getByTestId('editor-props')).toBeVisible();
    await expect(page.getByTestId('properties-title')).toBeVisible();

    const props = (await page.getByTestId('editor-props').boundingBox())!.width;

    expect(props).toBeLessThanOrEqual(1440 / 5);

    // Kattintás a vászon melletti üres területre → a kijelölés megszűnik, a panel csukódik.
    const stage = (await page.getByTestId('editor-stage').boundingBox())!;

    await page.mouse.click(stage.x + 8, stage.y + stage.height / 2);
    await expect(page.getByTestId('editor-props')).toHaveCount(0);

    // Újra kijelölve, majd a panel saját gombjával összecsukva (a kijelölés marad).
    await page.getByTestId('block-1-0-0').click();
    await expect(page.getByTestId('editor-props')).toBeVisible();
    await page.getByTestId('props-collapse').click();
    await expect(page.getByTestId('editor-props')).toHaveCount(0);
    await expect(page.getByTestId('block-1-0-0')).toHaveClass(/selected/);
    await page.getByTestId('props-expand').click();
    await expect(page.getByTestId('editor-props')).toBeVisible();

    // Escape is megszünteti a kijelölést.
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('editor-props')).toHaveCount(0);

    // A levél beállításai (tárgy, preheader) a felső sávból nyílnak, kijelölés nélkül.
    await page.getByTestId('editor-mail-settings').click();
    await expect(page.getByTestId('editor-subject')).toBeVisible();
});

test('az előnézet magassága nincs korlátozva, nincs belső görgetés: a hosszú levél teljesen renderelődik', async ({ page }) => {
    await page.goto(editorUrl);
    await expect(page.getByTestId('editor-canvas')).toBeVisible();

    const metrics = await page.evaluate(() => {
        const canvas = document.querySelector<HTMLElement>('[data-testid="editor-canvas"]')!;
        const stage = document.querySelector<HTMLElement>('[data-testid="editor-stage"]')!;
        const scrollers: string[] = [];

        // A vászontól a héj görgetett tartalom-oszlopáig (a „lap", Unified UI v2) egyetlen ős sem
        // görgethető és nincs fix magassága.
        const page = document.querySelector<HTMLElement>('.vt-shell__content')!;

        for (let node: HTMLElement | null = canvas; node && node !== page; node = node.parentElement) {
            const style = getComputedStyle(node);

            if (['auto', 'scroll'].includes(style.overflowY) || ['auto', 'scroll'].includes(style.overflow)) scrollers.push(node.className);
            if (node.style.height || node.style.blockSize || node.style.maxHeight) scrollers.push(`inline-height:${node.className}`);
        }

        const sections = canvas.querySelectorAll('[data-testid^="section-"]');
        const last = sections[sections.length - 1] as HTMLElement;

        return {
            scrollers,
            stageOverflow: getComputedStyle(stage).overflowY,
            stageMaxHeight: getComputedStyle(stage).maxHeight,
            canvasMaxHeight: getComputedStyle(canvas).maxHeight,
            // A vászon NEM vág le tartalmat: az utolsó szakasz alja a vászon alján belül van
            // (a `scrollHeight` itt a kijelölő-keret pár pixeles kilógását is számolná).
            canvasClipped: last.getBoundingClientRect().bottom - canvas.getBoundingClientRect().bottom,
            stageClipped: stage.scrollHeight - stage.clientHeight,
            canvasHeight: canvas.getBoundingClientRect().height,
            lastBottom: last.getBoundingClientRect().bottom - page.getBoundingClientRect().top + page.scrollTop,
            documentHeight: page.scrollHeight,
            viewport: window.innerHeight,
        };
    });

    expect(metrics.scrollers).toEqual([]);
    expect(metrics.stageOverflow).not.toMatch(/auto|scroll/);
    expect(metrics.stageMaxHeight).toBe('none');
    expect(metrics.canvasMaxHeight).toBe('none');
    expect(metrics.canvasClipped).toBeLessThanOrEqual(1);
    expect(metrics.stageClipped).toBeLessThanOrEqual(1);
    // Több képernyős levél: a vászon magasabb a nézetnél, és a LAP görget a végéig.
    expect(metrics.canvasHeight).toBeGreaterThan(metrics.viewport);
    expect(metrics.lastBottom).toBeLessThanOrEqual(metrics.documentHeight);

    // A lap aljára görgetve az utolsó szakasz (a lábléc) látható.
    const footer = page.locator('[data-testid="editor-canvas"] [data-type="footer"]');

    await footer.scrollIntoViewIfNeeded();
    await expect(footer).toBeInViewport();
});

test('új (nem marketing) üres sablon is láblécet kap', async ({ page }) => {
    await page.goto('/templates');
    await page.getByTestId('template-new').click();
    await page.getByTestId('create-blank').click();
    await page.getByTestId('template-name').fill(`E2E tranzakciós ${run}`);
    await page.getByTestId('template-marketing').click();
    await page.getByTestId('template-submit').click();
    await page.waitForURL(/\/templates\/[0-9a-f-]{36}$/);

    const sections = page.locator('[data-testid="editor-canvas"] > [data-testid^="section-"]');

    await expect(sections).toHaveCount(2);
    // A tranzakciós lábléc: cégnév + postai cím (leiratkozó link nélkül — az csak marketingen kötelező).
    await expect(sections.last()).toContainText('Dev workspace');
    await expect(sections.last().locator('[data-type="footer"]')).toHaveCount(0);
});
