import { expect, test, type Page } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 4. pont — AZ ÁTTEKINTŐ KÁRTYARÁCSA.
 *
 * A masonry (2:1 flex-hasáb, egymás alá rakott oldalkártyákkal) helyett DETERMINISZTIKUS CSS grid:
 * 4 / 2 / 1 oszlop, token-gap (8pt rács), és egy sor kártyái AZONOS tetővel és AZONOS aljjal.
 * A böngésző saját mérését olvassuk (bounding box), nem képet.
 */
const TOLERANCE = 1;

interface Box {
    id: string;
    top: number;
    bottom: number;
    left: number;
    right: number;
}

async function cells(page: Page): Promise<Box[]> {
    return page.locator('[data-dash-cell]').evaluateAll((nodes) =>
        nodes
            .map((node) => {
                const rect = node.getBoundingClientRect();

                return { id: node.getAttribute('data-dash-cell') ?? '', top: rect.top + window.scrollY, bottom: rect.bottom + window.scrollY, left: rect.left, right: rect.right };
            })
            .filter((box) => box.bottom > box.top),
    );
}

/** Sorokba csoportosítás a tető szerint (a tűrésen belül egy sor). */
function rows(boxes: Box[]): Box[][] {
    const sorted = [...boxes].sort((a, b) => a.top - b.top || a.left - b.left);
    const out: Box[][] = [];

    for (const box of sorted) {
        const row = out.find((candidate) => Math.abs(candidate[0].top - box.top) <= TOLERANCE);

        row ? row.push(box) : out.push([box]);
    }

    return out;
}

for (const [width, height, columns] of [
    [1440, 900, 4],
    [1280, 800, 4],
    // 1024-nél a 4. sáv már túl keskeny lenne (a kézbesíthetőség gombja kilógna) → 2 sáv.
    [1024, 768, 2],
    [820, 1180, 2],
    [390, 844, 1],
] as const) {
    test(`kártyarács ${width}×${height}: ${columns} oszlop, egy sorban azonos tető és alj`, async ({ page }) => {
        await page.setViewportSize({ width, height });
        await page.goto('/dashboard');
        await expect(page.getByTestId('dashboard-grid')).toBeVisible();

        const grid = page.getByTestId('dashboard-grid');

        // 1. A rács maga CSS grid (nem flex-masonry), a köz a 8pt rácson van.
        const style = await grid.evaluate((node) => {
            const computed = getComputedStyle(node);

            return { display: computed.display, gap: parseFloat(computed.columnGap), rowGap: parseFloat(computed.rowGap), tracks: computed.gridTemplateColumns.split(' ').length };
        });

        expect(style.display).toBe('grid');
        expect(style.gap % 8).toBe(0);
        expect(style.rowGap).toBe(style.gap);
        expect(style.tracks).toBe(columns);

        const boxes = await cells(page);

        expect(boxes.length).toBeGreaterThanOrEqual(6);

        const grouped = rows(boxes);

        // 2. Egy sor kártyáinak teteje ÉS alja azonos y-on.
        for (const row of grouped) {
            const bottoms = row.map((box) => box.bottom);

            expect(Math.max(...bottoms) - Math.min(...bottoms), `sor: ${row.map((box) => box.id).join(', ')}`).toBeLessThanOrEqual(TOLERANCE);
        }

        // 3. Egy sorban legfeljebb `columns` kártya, és az első sor TELE van.
        expect(grouped[0].length).toBe(columns);
        for (const row of grouped) expect(row.length).toBeLessThanOrEqual(columns);

        // 4. A kártyák széle oszlopokba rendeződik: a bal élek csak a rács oszlop-kezdetein ülnek.
        const firstRowLefts = grouped[0].map((box) => box.left);

        for (const box of boxes) {
            expect(firstRowLefts.some((left) => Math.abs(left - box.left) <= TOLERANCE), `bal él: ${box.id}`).toBe(true);
        }

        // 6. Egyetlen táblázat sem csordul túl vízszintesen a rácsban (nincs levágott oszlop).
        const overflowing = await grid.locator('table').evaluateAll((tables) =>
            tables
                .map((table) => {
                    let box: HTMLElement | null = table as HTMLElement;

                    // A legközelebbi KÁRTYÁIG minden ős: a táblát semmi nem vághatja és nem görgetheti.
                    const out: string[] = [];
                    while (box && !box.hasAttribute('data-dash-cell')) {
                        if (box.scrollWidth > box.clientWidth + 1) out.push(`${box.tagName}.${box.className} ${box.scrollWidth}>${box.clientWidth}`);
                        box = box.parentElement;
                    }

                    return out;
                })
                .flat(),
        );

        expect(overflowing).toEqual([]);

        // 7. A legutóbbi kampányok kártyája széles: 4 oszlopnál 3 sávot, egyébként a teljes sort kapja.
        const campaigns = boxes.find((box) => box.id === 'campaigns');
        const recent = boxes.find((box) => box.id === 'recent');

        if (campaigns && recent) {
            const ratio = (campaigns.right - campaigns.left) / (recent.right - recent.left);

            expect(ratio).toBeGreaterThan(columns === 4 ? 0.7 : 0.99);
        }

        // 5. A sorok között a köz pontosan a rács köze.
        for (let index = 1; index < grouped.length; index++) {
            const gap = grouped[index][0].top - Math.max(...grouped[index - 1].map((box) => box.bottom));

            expect(Math.abs(gap - style.gap), `sorköz ${index}`).toBeLessThanOrEqual(TOLERANCE);
        }
    });
}
