import { expect, test } from '@playwright/test';

// Vendég-állapot: a belépő és a landing (a bejelentkezett suite-állapot nem kell).
test.use({ storageState: { cookies: [], origins: [] } });

const FACES = [
    { family: 'Inter', font: '400 16px Inter' },
    { family: 'Space Grotesk', font: '600 16px "Space Grotesk"' },
    { family: 'JetBrains Mono', font: '400 16px "JetBrains Mono"' },
];

test('a betűk a saját originről töltődnek, és nincs kérés Google felé', async ({ page, baseURL }) => {
    const requests: string[] = [];
    page.on('request', (request) => requests.push(request.url()));

    await page.goto('/');
    await page.evaluate(() => document.fonts.ready);

    // A tényleg betöltött arcok (nem csak a deklaráltak): a belépő Inter + Space Grotesk-et használ.
    const loaded = await page.evaluate(() =>
        Array.from(document.fonts)
            .filter((face) => face.status === 'loaded')
            .map((face) => face.family.replace(/"/g, '')),
    );
    expect(loaded).toEqual(expect.arrayContaining(['Inter', 'Space Grotesk']));

    for (const { font } of FACES) {
        expect(await page.evaluate((f) => document.fonts.check(f), font), font).toBe(true);
    }

    const origin = new URL(baseURL ?? 'http://127.0.0.1:8000').origin;
    const fontRequests = requests.filter((url) => /\.woff2(\?|$)/.test(url));
    expect(fontRequests.length).toBeGreaterThan(0);
    for (const url of fontRequests) expect(new URL(url).origin).toBe(origin);

    expect(requests.filter((url) => /googleapis|gstatic/.test(url))).toEqual([]);
});

test('a két fő vágás preload, valódi buildelt asset-URL-lel', async ({ page }) => {
    await page.goto('/');

    const hrefs = await page.locator('link[rel="preload"][as="font"]').evaluateAll((links) =>
        links.map((link) => link.getAttribute('href') ?? ''),
    );
    expect(hrefs).toHaveLength(2);
    expect(hrefs.some((href) => /inter-latin[^/]*\.woff2$/.test(href))).toBe(true);
    expect(hrefs.some((href) => /space-grotesk-latin[^/]*\.woff2$/.test(href))).toBe(true);
    for (const href of hrefs) expect(href).toMatch(/\/build\/assets\/[^/]+\.woff2$/);
});
