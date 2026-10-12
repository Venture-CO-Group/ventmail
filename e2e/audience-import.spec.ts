import { type ChildProcess } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, type Locator, type Page, test } from '@playwright/test';
import { fixtureDir, startImportWorker, toWindows1250, writeBigCsv, writeXlsx } from './support/import-fixtures';

/*
 * v1.1 · 6. KÖZÖNSÉG-IMPORT a böngészőben — mind a négy bevitel:
 * kézi egyedi, beillesztett lista, CSV (Windows-1250, egyéni mező helyben), XLSX
 * (munkalap-választás) — és egy 300 000 soros CSV a háttérben.
 *
 * Amit a spec ténylegesen mér (nem a gombok létét):
 *  - a jogalap KÖTELEZŐ: nélküle a „Tovább" le van tiltva, és „subscribed" csak igazolt
 *    hozzájárulás + forrás + dátum mellett lesz az eredmény-státusz;
 *  - az előnézet kategóriánként számol (hibás, duplikátum, szerepcím…);
 *  - az eredmény számai és a LETÖLTHETŐ hibariport tartalma egyezik a fájllal;
 *  - a kontakt adatlapján látszik az import forrása.
 */

const run = Date.now().toString(36);

let worker: ChildProcess | null = null;

test.beforeAll(() => {
    worker = startImportWorker();
});

test.afterAll(() => {
    worker?.kill();
});

/** Egy VmStat-csempe számértéke (a felirat és a magyarázat számjegyei nélkül). */
async function statValue(tile: Locator): Promise<number> {
    return Number((await tile.locator('strong').innerText()).replace(/\D/g, ''));
}

async function openWizard(page: Page): Promise<void> {
    await page.goto('/audience');
    await page.getByTestId('audience-import').click();
    await expect(page).toHaveURL(/\/audience\/import$/);
    await expect(page.getByTestId('import-wizard')).toBeVisible();
}

/** 2. lépés: a javasolt leképezés elfogadása. */
async function acceptMapping(page: Page): Promise<void> {
    await expect(page.getByTestId('import-step-columns')).toBeVisible();
    await page.getByTestId('import-next').click();
    await expect(page.getByTestId('import-step-settings')).toBeVisible();
}

async function chooseBasis(page: Page, basis: 'verified_consent' | 'legitimate_interest' | 'unknown'): Promise<void> {
    await page.getByTestId(`legal-${basis}`).locator('label').click();
}

async function fillEvidence(page: Page, source: string, date = '2025-03-18'): Promise<void> {
    await page.getByTestId('consent-source').fill(source);
    await page.getByTestId('consent-date').fill(date);
}

/** 3. lépés → 4. lépés: előnézet; megvárja a háttér-számítást. */
async function makePreview(page: Page): Promise<void> {
    await page.getByTestId('import-next').click();
    await expect(page.getByTestId('preview-importable')).toBeVisible({ timeout: 300_000 });
}

/** 4. lépés → 6. lépés: indítás, és az eredmény megvárása. */
async function startAndWait(page: Page, timeout = 120_000): Promise<void> {
    await page.getByTestId('import-start').click();
    await expect(page.getByTestId('import-step-result')).toBeVisible({ timeout });
}

async function downloadReport(page: Page): Promise<string> {
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('import-download-errors').click()]);

    return readFileSync((await download.path()) as string, 'utf8');
}

test('kézi egyedi felvitel: a jogalap kötelező, a subscribed csak igazolt hozzájárulással', async ({ page }) => {
    const email = `e2e-${run}-kezi@pelda.hu`;

    await openWizard(page);

    await page.getByTestId('import-email').fill(email);
    await page.getByTestId('import-first-name').fill('Egyed');
    await page.getByTestId('import-last-name').fill('Iván');
    await page.getByTestId('import-next').click();

    await acceptMapping(page);

    // JOGALAP NÉLKÜL nem lehet továbblépni.
    const next = page.getByTestId('import-next');
    await expect(next).toBeDisabled();

    // Igazolt hozzájárulás, de forrás és dátum nélkül sem.
    await chooseBasis(page, 'verified_consent');
    await expect(next).toBeDisabled();
    await expect(page.getByTestId('import-status-box')).toContainText('pending');

    await fillEvidence(page, `Double opt-in a weboldalon, e2e ${run}`);
    await expect(next).toBeEnabled();
    await expect(page.getByTestId('import-status-box')).toContainText('subscribed');

    await makePreview(page);
    expect(await statValue(page.getByTestId('preview-importable'))).toBe(1);

    await startAndWait(page);
    expect(await statValue(page.getByTestId('result-new'))).toBe(1);
    await expect(page.getByTestId('import-audit')).toContainText(`e2e ${run}`);
    // Nincs hiba → nincs letölthető hibariport.
    await expect(page.getByTestId('import-download-errors')).toHaveCount(0);

    // A KONTAKT ADATLAPJÁN látszik az import forrása és jogalapja.
    await page.getByTestId('import-view-contacts').click();
    await page.getByTestId('contact-search').fill(email);
    await page.getByTestId('contact-search-submit').click();
    await page.getByTestId('contact-link').first().click();

    await expect(page.getByTestId('contact-email')).toHaveText(email);
    await expect(page.getByTestId('contact-status')).toHaveText('subscribed');
    await expect(page.getByTestId('contact-history')).toContainText(`e2e ${run}`);
});

test('beillesztett lista: hibás, duplikátum és szerepalapú cím külön számlálóban', async ({ page }) => {
    await openWizard(page);

    await page.getByRole('tab').nth(1).click();
    await page.getByTestId('import-paste').fill(
        [
            `e2e-${run}-p1@pelda.hu`,
            `e2e-${run}-p2@pelda.hu, e2e-${run}-p3@pelda.hu`,
            `E2E-${run}-P1@pelda.hu`, // duplikátum (kisbetűsítés után)
            `info@e2e-${run}.example`, // szerepalapú
            'ez-nem-cim',
        ].join('\n'),
    );
    await page.getByTestId('import-next').click();

    await acceptMapping(page);

    // Nem igazolt jogalap: forrás és dátum nélkül is mehet, az eredmény pending.
    await chooseBasis(page, 'unknown');
    await expect(page.getByTestId('import-status-box')).toContainText('pending');

    await makePreview(page);

    expect(await statValue(page.getByTestId('preview-importable'))).toBe(3);
    expect(await statValue(page.getByTestId('preview-invalid'))).toBe(1);
    expect(await statValue(page.getByTestId('preview-duplicate'))).toBe(1);
    expect(await statValue(page.getByTestId('preview-role'))).toBe(1);
    await expect(page.getByTestId('preview-samples')).toBeVisible();

    await startAndWait(page);

    expect(await statValue(page.getByTestId('result-new'))).toBe(3);
    expect(await statValue(page.getByTestId('result-errors'))).toBe(1);
    expect(await statValue(page.getByTestId('result-skipped'))).toBe(2);

    const report = await downloadReport(page);

    expect(report).toContain('ez-nem-cim');
    expect(report).toContain('invalid_email');
    expect(report).toContain('duplicate');
    expect(report).toContain('role_address');
});

test('CSV (Windows-1250, pontosvessző): egyéni mező helyben, hibariport, képlet-védelem', async ({ page }) => {
    const dir = fixtureDir();
    const file = join(dir, 'vendegek.csv');

    // A hibás sor „címe" képlet-kezdetű (`+36…`): a hibariportban aposztróf-prefixet kell kapnia.
    const rows = [
        'E-mail cím;Vezetéknév;Keresztnév;Születésnap;Törzsvendég-kód',
        `e2e-${run}-c1@pelda.hu;Kovács;Éva;1988-04-12;TV-1`,
        `e2e-${run}-c2@pelda.hu;Nagy;Béla;2001.05.06.;TV-2`,
        '+36301234567;Hibás;Cím;;',
        `E2E-${run}-C1@pelda.hu;Dup;Sor;;`,
        '',
    ];

    writeFileSync(file, toWindows1250(rows.join('\n')));

    await openWizard(page);

    await page.getByRole('tab').nth(2).click();
    await page.getByTestId('import-file-input').setInputFiles(file);

    // Az oszlopok lépésnél vagyunk, és a kódolás-felismerés látszik a 2. lépésben a mintán.
    await expect(page.getByTestId('import-step-columns')).toBeVisible();
    await expect(page.getByTestId('mapping-select-0').locator('select')).toHaveValue('email');
    await expect(page.getByTestId('import-mapping')).toContainText('Törzsvendég-kód');
    await expect(page.getByTestId('import-mapping')).toContainText('Kovács');

    // ÚJ EGYÉNI MEZŐ helyben: a „Születésnap" oszlopból.
    await page.getByTestId('mapping-select-3').locator('select').selectOption('__new__');
    await expect(page.getByTestId('new-field-form')).toBeVisible();
    await page.getByTestId('new-field-key').fill(`szuletes_${run}`);
    await page.getByTestId('new-field-type').locator('select').selectOption('date');
    await page.getByTestId('new-field-create').click();
    await expect(page.getByTestId('new-field-form')).toHaveCount(0);
    await expect(page.getByTestId('mapping-select-3').locator('select')).toHaveValue(`attr:szuletes_${run}`);

    await page.getByTestId('import-next').click();
    await expect(page.getByTestId('import-step-settings')).toBeVisible();

    await page.getByTestId('tag-input').fill(`e2e-${run}`);
    await page.getByTestId('tag-input').press('Enter');
    await expect(page.getByTestId(`tag-e2e-${run}`)).toBeVisible();

    await chooseBasis(page, 'legitimate_interest');
    await fillEvidence(page, 'Vendégkönyv, 2025 ősz');

    await makePreview(page);

    expect(await statValue(page.getByTestId('preview-importable'))).toBe(2);
    expect(await statValue(page.getByTestId('preview-invalid'))).toBe(1);
    expect(await statValue(page.getByTestId('preview-duplicate'))).toBe(1);

    await startAndWait(page);

    expect(await statValue(page.getByTestId('result-new'))).toBe(2);
    expect(await statValue(page.getByTestId('result-errors'))).toBe(1);
    expect(await statValue(page.getByTestId('result-skipped'))).toBe(1);

    const report = await downloadReport(page);

    expect(report).toContain('invalid_email');
    // KÉPLET-védelem: a `+`-kezdetű cella aposztróf-prefixet kapott (a táblázatkezelő szövegnek veszi).
    expect(report).toMatch(/^4,'\+36301234567,/m);
    expect(report).not.toMatch(/,\+36301234567,/);

    // A legitimate interest jogalap NEM ad subscribed státuszt: re-permission szükséges.
    await expect(page.getByTestId('import-audit')).toContainText('pending');
});

test('XLSX: munkalap-választás, majd ugyanaz a folyamat', async ({ page }) => {
    const dir = fixtureDir();
    const file = join(dir, 'vendegek.xlsx');

    writeXlsx(file, {
        Üres: [['semmi']],
        Vendégek: [
            ['Email', 'Keresztnév', 'Cég neve'],
            [`e2e-${run}-x1@pelda.hu`, 'Xénia', 'Acme Kft.'],
            [`e2e-${run}-x2@pelda.hu`, 'Xavér', 'Beta Kft.'],
            [`e2e-${run}-x3@pelda.hu`, 'Xaver', ''],
        ],
    });

    await openWizard(page);

    await page.getByRole('tab').nth(3).click();
    await page.getByTestId('import-file-input').setInputFiles(file);

    await expect(page.getByTestId('import-step-columns')).toBeVisible();

    // Alapból az első munkalap (Üres) — átváltunk a másodikra, a leképezés újraépül.
    await page.getByTestId('import-sheet').locator('select').selectOption('Vendégek');
    await expect(page.getByTestId('import-mapping')).toContainText('Keresztnév');
    await expect(page.getByTestId('mapping-select-0').locator('select')).toHaveValue('email');
    await expect(page.getByTestId('mapping-select-2').locator('select')).toHaveValue('attr:company');

    await page.getByTestId('import-next').click();
    await expect(page.getByTestId('import-step-settings')).toBeVisible();

    await chooseBasis(page, 'verified_consent');
    await fillEvidence(page, `XLSX-export, e2e ${run}`);

    await makePreview(page);
    expect(await statValue(page.getByTestId('preview-importable'))).toBe(3);

    await startAndWait(page);
    expect(await statValue(page.getByTestId('result-new'))).toBe(3);
});

/*
 * A 300 000 SOROS CSV a HÁTTÉRBEN: valódi queue-n (`database` kapcsolat + worker), a böngésző
 * csak a folyamatjelzőt látja. CI-ban mindig fut; lokálisan opt-in (`E2E_IMPORT_300K=1`),
 * mert a 300 000 append-only consent-esemény a dev-adatbázisban örökre ott marad.
 */
test('300 000 soros CSV háttérben, folyamatjelzővel', async ({ page }) => {
    test.skip(!process.env.CI && !process.env.E2E_IMPORT_300K, 'lokálisan opt-in: E2E_IMPORT_300K=1 (dedikált adatbázissal)');
    test.setTimeout(20 * 60_000);

    const rows = 300_000;
    const dir = fixtureDir();
    const file = join(dir, 'nagy.csv');

    const { invalid, duplicates } = await writeBigCsv(file, rows, `e2e${run}n`);

    await openWizard(page);

    await page.getByRole('tab').nth(2).click();
    await page.getByTestId('import-file-input').setInputFiles(file);

    await acceptMapping(page);
    await chooseBasis(page, 'verified_consent');
    await fillEvidence(page, `Teljesítmény-e2e ${run}`);

    await makePreview(page);

    expect(await statValue(page.getByTestId('preview-importable'))).toBe(rows - invalid - duplicates);

    await page.getByTestId('import-start').click();

    // A FOLYAMATJELZŐ látszik, és nem a kérésben fut: a haladás a szerver-oldali állapotból jön.
    await expect(page.getByTestId('import-step-progress')).toBeVisible({ timeout: 60_000 });
    await expect(page.getByTestId('progress-phases')).toBeVisible();

    await expect(page.getByTestId('import-step-result')).toBeVisible({ timeout: 15 * 60_000 });

    expect(await statValue(page.getByTestId('result-new'))).toBe(rows - invalid - duplicates);
    expect(await statValue(page.getByTestId('result-errors'))).toBe(invalid);
});
