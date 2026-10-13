import { type ChildProcess, execFileSync, spawn } from 'node:child_process';
import { createWriteStream, mkdtempSync, writeFileSync } from 'node:fs';
import { once } from 'node:events';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/*
 * Az import e2e-specjének SEGÉDEI: fixture-generálás FUTÁSIDŐBEN (semmi nem commitolt),
 * és a háttér-worker, amin a 300 000 soros import ténylegesen QUEUE-n fut.
 */

/** Új ideiglenes könyvtár a fixture-öknek. */
export function fixtureDir(): string {
    return mkdtempSync(join(tmpdir(), 'vm-e2e-import-'));
}

/** Windows-1250 kódolás a magyar ékezetes betűkre (a Node nem ismeri natívan). */
export function toWindows1250(text: string): Buffer {
    const table: Record<string, number> = {
        á: 0xe1, é: 0xe9, í: 0xed, ó: 0xf3, ö: 0xf6, ő: 0xf5, ú: 0xfa, ü: 0xfc, ű: 0xfb,
        Á: 0xc1, É: 0xc9, Í: 0xcd, Ó: 0xd3, Ö: 0xd6, Ő: 0xd5, Ú: 0xda, Ü: 0xdc, Ű: 0xdb,
    };

    return Buffer.from(
        [...text].map((char) => {
            const code = char.charCodeAt(0);

            if (code < 0x80) return code;
            if (char in table) return table[char];

            throw new Error(`Nincs Windows-1250 megfelelője: ${char}`);
        }),
    );
}

/** XLSX írása a repó saját openspout-jával (lásd `make-xlsx.php`). */
export function writeXlsx(path: string, sheets: Record<string, string[][]>): void {
    const json = `${path}.json`;

    writeFileSync(json, JSON.stringify(sheets));
    execFileSync('php', ['e2e/support/make-xlsx.php', path, json], { cwd: process.cwd() });
}

/** Nagy CSV streamelve írva (a memória nem nő a sorok számával). */
export async function writeBigCsv(path: string, rows: number, prefix: string): Promise<{ invalid: number; duplicates: number }> {
    const out = createWriteStream(path);
    let invalid = 0;
    let duplicates = 0;

    out.write('email,first_name,last_name,city\n');

    for (let i = 1; i <= rows; i++) {
        let email = `${prefix}${i}@nagylista.example`;

        if (i % 1000 === 0) {
            email = `hibas-cim-${i}`;
            invalid++;
        } else if (i % 997 === 0) {
            // Az előző sor címe NAGYBETŰS doménnel: a szerver normalizál, tehát duplikátum.
            email = `${prefix}${i - 1}@NAGYLISTA.EXAMPLE`;
            duplicates++;
        }

        if (!out.write(`${email},Név${i},Teszt,Budapest\n`)) {
            await once(out, 'drain');
        }
    }

    out.end();
    await once(out, 'finish');

    return { invalid, duplicates };
}

/**
 * A HÁTTÉR-WORKER: az `imports` sort fogyasztó `queue:work` (a webszerver
 * `AUDIENCE_IMPORT_QUEUE_CONNECTION=database`-szel indul, lásd `playwright.config.ts`).
 *
 * Ha a szervert más indította (`reuseExistingServer`) és szinkron sorral fut, a worker
 * egyszerűen tétlen marad, az import pedig a kérésben lefut — a spec mindkettővel működik.
 */
export function startImportWorker(): ChildProcess {
    return spawn(
        'php',
        ['-d', 'memory_limit=512M', 'artisan', 'queue:work', 'database', '--queue=imports,imports-inspect', '--tries=1', '--timeout=3600', '--sleep=1'],
        {
            cwd: process.cwd(),
            // A worker belső (nem import) jobjai szinkron futnak; a cache `array`, mint a webszervernél
            // (a `queue:work` a cache-be írja az újraindítás-jelzést, és a Redis-bővítmény nem kell).
            env: { ...process.env, QUEUE_CONNECTION: 'sync', CACHE_STORE: 'array', AUDIENCE_IMPORT_QUEUE_CONNECTION: 'database' },
            stdio: 'ignore',
        },
    );
}
