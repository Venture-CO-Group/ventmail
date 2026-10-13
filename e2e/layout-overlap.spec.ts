import { expect, test, type Page } from '@playwright/test';

/*
 * v1.1 · WHITESPACE ÉS ÁTFEDÉS-KAPU.
 *
 * Minden fő képernyő, három szélességen (1440 / 1024 / 390): a böngésző SAJÁT mérését
 * olvassuk, nem képernyőképet nézünk. Három dolgot tiltunk:
 *
 *  1. két SZÖVEG-doboz egymásba lóg (a szöveg-csomópontok tényleges sor-dobozai, a
 *     levágott — `overflow` — részük nélkül; 1px tűrés; ugyanazon szülő alatti csomópontok
 *     egy folyamot alkotnak, azokat nem hasonlítjuk);
 *  2. egy tartó VÍZSZINTESEN túlcsordul (scrollWidth > clientWidth), kivéve a szándékosan
 *     görgethető (`overflow-x: auto|scroll`) és a `text-overflow: ellipsis`-szel levágott
 *     elemeket;
 *  3. az OLDAL vízszintesen görget.
 *
 * Az adat NEM üres: a seed hosszú neveket, e-mail-címet, domaint, URL-t ad, mert az átfedés
 * épp a hosszú szövegnél jelenik meg. A seed IDEMPOTENS: a nevekben ROGZITETT azonosító van, és minden
 * elem csak akkor jön létre, ha az adott képernyőn még nincs meg (ismételt futás nem hizlal). A
 * webhook-végpontot az `afterAll` törli (a `platform-screens.spec.ts` üres listát vár); a
 * többi seedelt sor szándékosan marad — ugyanaz a sor a következő futásban újrahasznosul.
 */
test.describe.configure({ mode: 'serial' });

const WIDTHS = [1440, 1024, 390] as const;
const TOLERANCE = 1;

const run = 'layoutseed';
const LONG = `Nagyon-hosszu-nev-amely-semmilyen-szokozt-nem-tartalmaz-${run}-es-tovabb-folytatodik-a-sor-vegeig-X`;
const LONG_WORDS = `Egy meglehetosen hosszu, tobb szobol allo megnevezes a kampanyhoz ${run} amely tobb sorba tordelodik`;
const LONG_EMAIL = `nagyon.hosszu.felhasznalo.nev.amely.nem.torheto+${run}@egy-rendkivul-hosszu-subdomain.pelda-szervezet-neve.example.co.uk`;
const LONG_DOMAIN = `rendkivul-hosszu-aldomain-${run}.egy-masik-nagyon-hosszu-szegmens.pelda-szervezet.example.co.uk`;
// A webhook-cél SSRF-védett (valódi DNS-t kérdezne) → literál, publikus IP; a hosszt az útvonal adja.
const LONG_URL = `https://93.184.216.34/nagyon/hosszu/utvonal/amely/sokszegmensu/${run}/es/tovabb/folytatodik?token=abcdefghijklmnopqrstuvwxyz0123456789`;

const STATIC_PAGES = [
    '/dashboard',
    '/audience',
    '/audience/segments',
    '/audience/segments/new',
    '/audience/tags',
    '/forms',
    '/templates',
    '/campaigns',
    '/journeys',
    '/analytics',
    '/domains',
    '/domains/wizard',
    '/api-keys',
    '/smtp-credentials',
    '/suppressions',
    '/suppressions?list=manual',
    '/webhooks',
    '/stored-templates',
    '/ip-pools',
    '/deliverability',
    '/logs',
    '/logs/statistics',
    '/billing',
    '/inbound',
    '/validation',
    '/members',
    '/profile',
];

const detailPages: string[] = [];

interface Posted {
    status: number;
    /** A végső cím a redirect UTÁN: a létrehozás a szerkesztőbe irányít. */
    path: string;
}

/*
 | A BÖNGÉSZŐBŐL posztolunk (a `page.request` a `__Host-` süti + XSRF párost nem küldi vissza
 | érvényesen → 419): ugyanazt csinálja, mint az Inertia/axios — az `XSRF-TOKEN` sütit a
 | fejlécbe teszi.
 */
async function post(page: Page, url: string, data: Record<string, unknown>): Promise<Posted> {
    const result = await page.evaluate(
        async ({ target, body }) => {
            const raw = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
            const token = raw ? decodeURIComponent(raw.slice('XSRF-TOKEN='.length)) : '';
            const response = await fetch(target, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-XSRF-TOKEN': token },
                body: JSON.stringify(body),
            });

            return { status: response.status, path: new URL(response.url).pathname };
        },
        { target: url, body: data },
    );

    if (process.env.LAYOUT_DEBUG) console.log('seed', url, result.status, result.path);

    return result;
}

test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    await page.goto('/dashboard'); // a munkamenet + XSRF süti

    // Idempotens seed: csak akkor posztolunk, ha a képernyőn még nincs meg az érték.
    const seedOnce = async (path: string, needle: string, url: string, data: Record<string, unknown>): Promise<void> => {
        await page.goto(path);

        if (!(await page.locator('body').innerText()).includes(needle)) await post(page, url, data);
    };

    await page.goto('/dev/seed-logs');
    await seedOnce('/domains', LONG_DOMAIN, '/domains', { name: LONG_DOMAIN });
    await seedOnce('/api-keys', LONG, '/api-keys', { name: LONG, scopes: ['messages:send'], channels: [], testmode: false });
    await seedOnce('/suppressions?list=manual', LONG_EMAIL, '/suppressions', { email: LONG_EMAIL, reason: 'manual', appliesTo: 'all' });
    await seedOnce('/webhooks', LONG, '/webhooks', { name: LONG, url: LONG_URL, events: ['delivered', 'bounced'] });
    await seedOnce('/audience', LONG_WORDS, '/audience/lists', { name: LONG_WORDS });
    await seedOnce('/forms', LONG_WORDS, '/forms', {
        name: LONG_WORDS,
        listUuid: null,
        minSeconds: 5,
        policyVersion: LONG,
        consentText: LONG_WORDS,
        redirectUrl: LONG_URL,
    });
    await seedOnce('/members', LONG_EMAIL, '/members/invitations', { email: LONG_EMAIL, roles: ['editor'] });
    await seedOnce('/templates', LONG_WORDS, '/templates', { name: LONG_WORDS, subject: LONG_WORDS, marketing: true });
    await seedOnce('/campaigns', LONG_WORDS, '/campaigns', { name: LONG_WORDS, subject: LONG_WORDS });
    await seedOnce('/journeys', LONG_WORDS, '/journeys', { name: LONG_WORDS, trigger: 'api_event' });

    // A részletező oldalakat a listák linkjeiből találjuk meg (a létrehozás útvonalából nem lehet:
    // ismételt futásnál a sor már létezik).
    for (const [list, prefix] of [
        ['/templates', '/templates/'],
        ['/campaigns', '/campaigns/'],
        ['/journeys', '/journeys/'],
    ] as const) {
        // A kampánylista LAPOZOTT (v1.1 · 5. modul): a seedelt sort kereséssel hozzuk az első lapra.
        await page.goto(list === '/campaigns' ? `/campaigns?search=${encodeURIComponent(run)}` : list);

        const href = await page
            .locator(`a[href^="${prefix}"]`, { hasText: LONG_WORDS })
            .first()
            .getAttribute('href');

        if (href) {
            detailPages.push(href);
            if (prefix === '/campaigns/') detailPages.push(`/analytics/campaigns/${href.split('/').pop()}`);
        }
    }

    // A napló egy sora → trace (a „Trace" gomb navigál, nincs href: az URL-t a kattintás után olvassuk).
    await page.goto('/logs');
    await page.getByTestId('log-trace').first().click();
    await page.waitForURL(/\/logs\/[0-9a-f-]{36}/);
    detailPages.push(new URL(page.url()).pathname);

    await context.close();
});

/*
 | TAKARÍTÁS: a webhook-végpontot a seed hozza létre, és a `platform-screens.spec.ts` ÜRES
 | végpont-listát vár (`endpoints-empty`). A fájlok névsorban futnak, ez a spec előttük —
 | ezért a végpontot a végén töröljük, hogy ne hagyjon nyomot a többi tesztnek.
 */
test.afterAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'e2e/.auth/state.json' });
    const page = await context.newPage();

    await page.goto('/webhooks');

    // CSAK a seedelt végpontot (név: LONG): a sor `endpoint-<uuid>` testid-t visel.
    const ids = await page
        .locator('[data-testid^="endpoint-"]', { hasText: LONG })
        .evaluateAll((rows) => rows.map((row) => (row.getAttribute('data-testid') ?? '').replace('endpoint-', '')));

    for (const id of ids) {
        const button = page.getByTestId(`delete-${id}`);

        await button.click();
        // Az Inertia újrarenderelését megvárjuk: a régi gomb ELTŰNIK — különben a következő
        // kör ugyanarra a (már törölt) sorra kattintana, és a szerver 404-et adna.
        await expect(button).toHaveCount(0);
    }

    await context.close();
});

interface Finding {
    kind: 'text-overlap' | 'overflow' | 'page-scroll';
    message: string;
}

/** A böngészőben futó mérés: a találatok emberi szövegként jönnek vissza. */
async function measure(page: Page, tolerance: number): Promise<Finding[]> {
    return page.evaluate((tol): Finding[] => {
        const findings: Finding[] = [];
        const describe = (element: Element): string => {
            const id = element.getAttribute('data-testid');
            const cls = typeof element.className === 'string' ? element.className.trim().split(/\s+/).slice(0, 2).join('.') : '';

            return `<${element.tagName.toLowerCase()}${id ? ` data-testid="${id}"` : ''}${cls ? ` .${cls}` : ''}>`;
        };

        const root = document.documentElement;

        if (root.scrollWidth > window.innerWidth + tol) {
            findings.push({
                kind: 'page-scroll',
                message: `az oldal vízszintesen görget (scrollWidth ${root.scrollWidth} > ${window.innerWidth})`,
            });
        }

        const isVisible = (element: Element): boolean => {
            for (let node: Element | null = element; node; node = node.parentElement) {
                const style = getComputedStyle(node);

                if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') return false;
                if (node.classList.contains('vm-visually-hidden') || node.getAttribute('aria-hidden') === 'true') return false;
            }

            return true;
        };

        /* Levágás: a szülők `overflow`-ja szerint metszett látható téglalap. */
        const clipTo = (element: Element, rect: DOMRect): DOMRect | null => {
            let left = rect.left;
            let top = rect.top;
            let right = rect.right;
            let bottom = rect.bottom;

            for (let node: Element | null = element; node && node !== document.body; node = node.parentElement) {
                const style = getComputedStyle(node);

                if (style.overflowX !== 'visible' || style.overflowY !== 'visible') {
                    const box = node.getBoundingClientRect();

                    if (style.overflowX !== 'visible') {
                        left = Math.max(left, box.left);
                        right = Math.min(right, box.right);
                    }

                    if (style.overflowY !== 'visible') {
                        top = Math.max(top, box.top);
                        bottom = Math.min(bottom, box.bottom);
                    }
                }
            }

            return right - left > 0 && bottom - top > 0 ? new DOMRect(left, top, right - left, bottom - top) : null;
        };

        interface Box {
            node: Text;
            parent: Element;
            rects: DOMRect[];
            text: string;
        }

        const boxes: Box[] = [];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);

        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const text = node as Text;
            const value = text.data.trim();
            const parent = text.parentElement;

            if (!value || !parent) continue;
            if (['SCRIPT', 'STYLE', 'OPTION', 'SELECT', 'TEXTAREA', 'NOSCRIPT', 'TITLE'].includes(parent.tagName)) continue;
            if (!isVisible(parent)) continue;

            const range = document.createRange();

            range.selectNodeContents(text);

            const rects: DOMRect[] = [];

            for (const raw of Array.from(range.getClientRects())) {
                if (raw.width < 1 || raw.height < 1) continue;

                const clipped = clipTo(parent, raw);

                if (clipped) rects.push(clipped);
            }

            if (rects.length) boxes.push({ node: text, parent, rects, text: value });
        }

        const overlaps = (a: DOMRect, b: DOMRect): boolean =>
            Math.min(a.right, b.right) - Math.max(a.left, b.left) > tol &&
            Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > tol;

        const seen = new Set<string>();

        for (let i = 0; i < boxes.length; i++) {
            for (let j = i + 1; j < boxes.length; j++) {
                const a = boxes[i];
                const b = boxes[j];

                // Ugyanazon szülő alatti szöveg-csomópontok egy beszúrt folyam.
                if (a.parent === b.parent) continue;

                if (!a.rects.some((ra) => b.rects.some((rb) => overlaps(ra, rb)))) continue;

                const key = `${describe(a.parent)}|${describe(b.parent)}`;

                if (seen.has(key)) continue;

                seen.add(key);
                findings.push({
                    kind: 'text-overlap',
                    message: `átfedő szövegek: "${a.text.slice(0, 40)}" ${describe(a.parent)}  ×  "${b.text.slice(0, 40)}" ${describe(b.parent)}`,
                });
            }
        }

        for (const element of Array.from(document.body.querySelectorAll('*'))) {
            const style = getComputedStyle(element);

            if (style.display === 'inline' || style.display === 'contents' || style.display.startsWith('table-column')) continue;
            if (['svg', 'path', 'option', 'select', 'input', 'textarea', 'script', 'style'].includes(element.tagName.toLowerCase())) continue;
            if (style.overflowX === 'auto' || style.overflowX === 'scroll') continue;
            if (style.textOverflow === 'ellipsis') continue;
            // A VÉGTELEN VÁSZON (journey, v1.1 · 6. modul — Vue Flow) belső síkja: a pásztázás és a
            // nagyítás MAGA a túlnyúlás, a külső `.vue-flow` vágja. A csomópontok és a vászon
            // körüli elemek mérése változatlan.
            if (element.classList.contains('vue-flow__container')) continue;
            if (!isVisible(element)) continue;
            if (element.clientWidth === 0) continue;

            if (element.scrollWidth > element.clientWidth + tol) {
                findings.push({
                    kind: 'overflow',
                    message: `vízszintes túlcsordulás: ${describe(element)} scrollWidth ${element.scrollWidth} > clientWidth ${element.clientWidth} — "${(element.textContent ?? '').trim().slice(0, 50)}"`,
                });
            }
        }

        return findings;
    }, tolerance);
}

async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    // Az Inertia/Vue mount után a nav kompakt-állapota egy frame-mel késik.
    await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
}

/*
 | A KAPU NEM LEHET ÜRES ADATON ZÖLD: ha a seed elromlik, a mérés semmit sem mérne. Ez a teszt
 | azt őrzi, hogy a hosszú értékek tényleg ott vannak a képernyőkön, ahol az átfedés lakik.
 */
test('a seed hosszú adatot ad a képernyőknek', async ({ page }) => {
    for (const [path, needle] of [
        ['/api-keys', LONG],
        ['/suppressions?list=manual', LONG_EMAIL],
        ['/webhooks', LONG_URL],
        ['/domains', LONG_DOMAIN],
        ['/members', LONG_EMAIL],
        ['/audience', LONG_WORDS],
        ['/forms', LONG_WORDS],
    ] as const) {
        await page.goto(path);
        await expect(page.locator('body'), `${path}: hiányzik a seedelt hosszú adat`).toContainText(needle);
    }

    for (const prefix of ['/templates/', '/campaigns/', '/journeys/', '/logs/', '/analytics/campaigns/']) {
        expect(
            detailPages.some((path) => path.startsWith(prefix)),
            `hiányzik a(z) ${prefix} részletező oldal a mérésből`,
        ).toBe(true);
    }
});

/*
 | A MÉRŐ ÖNELLENŐRZÉSE: egy kapu, ami nem tud elbukni, nem kapu. Szándékosan rossz DOM-ot
 | adunk neki, és mindhárom hibafajtát meg kell találnia.
 */
test('a mérő megtalálja az átfedést, a túlcsordulást és az oldal-görgetést', async ({ page }) => {
    await page.setViewportSize({ width: 600, height: 400 });
    await page.setContent(`<!doctype html>
        <body style="margin:0">
            <p style="position:absolute;left:10px;top:10px;margin:0">Első szöveg itt</p>
            <p style="position:absolute;left:14px;top:12px;margin:0">Második szöveg ott</p>
            <div style="position:absolute;top:100px;left:0;width:80px;overflow:hidden">
                <span style="display:block;white-space:nowrap">egy-nagyon-hosszu-torhetetlen-szo-ami-kilog</span>
            </div>
            <div style="position:absolute;top:200px;left:0;width:900px;height:10px">x</div>
        </body>`);

    const kinds = new Set((await measure(page, TOLERANCE)).map((finding) => finding.kind));

    expect([...kinds].sort()).toEqual(['overflow', 'page-scroll', 'text-overlap']);
});

for (const width of WIDTHS) {
    test(`nincs átfedés és túlcsordulás — ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });

        const failures: string[] = [];

        for (const path of [...STATIC_PAGES, ...detailPages]) {
            const response = await page.goto(path);

            if (!response || response.status() >= 400) {
                failures.push(`[${path} @ ${width}] HTTP ${response?.status() ?? 'nincs válasz'}`);

                continue;
            }

            await settle(page);

            for (const finding of await measure(page, TOLERANCE)) {
                failures.push(`[${path} @ ${width}] ${finding.kind}: ${finding.message}`);
            }
        }

        expect(failures, failures.join('\n')).toEqual([]);
    });
}
