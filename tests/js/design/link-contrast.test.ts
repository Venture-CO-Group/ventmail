import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 | D292 — a KÖZÖS LINK-STÍLUS: a felület linkjei tokenes színt kapnak (nem a böngésző natív kékjét),
 | és ez a szín a sötét téma MINDEN felületén (háttér, emelt háttér, kártya, kártya-2) eléri a WCAG AA
 | 4,5 : 1 kontrasztot. A token értékét a @venture/design kiadott `tokens.css`-éből olvassuk.
 */
const tokens = readFileSync(resolve('node_modules/@venture/design/dist/tokens.css'), 'utf8');
const layout = readFileSync(resolve('resources/js/layout.css'), 'utf8');

function block(selector: string): string {
    const start = tokens.indexOf(`${selector} {`);
    expect(start, `${selector} blokk`).toBeGreaterThanOrEqual(0);

    return tokens.slice(start, tokens.indexOf('}', start));
}

function token(source: string, name: string): string {
    const match = new RegExp(`${name}:\\s*(#[0-9A-Fa-f]{6})`).exec(source);
    expect(match, name).not.toBeNull();

    return match![1];
}

function luminance(hex: string): number {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

const contrast = (a: string, b: string): number => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

describe('közös link-stílus', () => {
    const rule = /\/\* D292 · KÖZÖS LINK \*\/[\s\S]*?color:\s*var\((--vt-[a-z0-9-]+)\)/.exec(layout);

    it('a layout.css tokenes link-színt ad a felület linkjeinek', () => {
        expect(rule, 'a D292 link-szabály hiányzik a layout.css-ből').not.toBeNull();
    });

    it('a link-szín a sötét téma minden felületén ≥ 4,5 : 1', () => {
        const name = rule![1];
        const color = token(tokens, name);
        const dark = block('[data-theme="dark"]');

        for (const surface of ['--vt-bg', '--vt-bg-elevated', '--vt-surface', '--vt-surface-2']) {
            expect(contrast(color, token(dark, surface)), `${name} vs ${surface}`).toBeGreaterThanOrEqual(4.5);
        }
    });
});
