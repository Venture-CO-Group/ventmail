/*
 | GRAFIKON-TÉMA (v1.1 · 8. modul) — a Venture palettája TOKENBŐL, a PNG-export segédei.
 |
 | A canvas CSS-változót nem ért: a token ÉRTÉKÉT a mount után a dokumentum számított stílusából
 | olvassuk. A sötét export-háttér a `--vt-bg` a SÖTÉT témában (egy ideiglenes, `data-theme="dark"`
 | elemről mérve) — nem beégetett szín, és világos felületen is sötét PNG-t ad.
 */

/** A kategória-sorozatok palettája (a Venture tokenek NEVEI, sorrendben). */
export const CATEGORY_TOKENS = [
    '--vt-color-accent-500',
    '--vt-color-cyan-400',
    '--vt-color-violet-400',
    '--vt-color-success',
    '--vt-color-warning',
    '--vt-color-danger',
    '--vt-color-accent-300',
    '--vt-color-cyan-600',
    '--vt-color-violet-600',
    '--vt-color-neutral-400',
    '--vt-color-neutral-600',
];

export type TokenResolver = (token: string) => string;

export function tokenResolver(element: Element = document.documentElement): TokenResolver {
    const style = getComputedStyle(element);

    return (token: string) => style.getPropertyValue(token).trim();
}

/** A sötét téma háttérszíne — a `[data-theme="dark"]` token-készletből. */
export function darkBackground(): string {
    const probe = document.createElement('div');
    probe.setAttribute('data-theme', 'dark');
    probe.style.display = 'none';
    document.body.appendChild(probe);
    const value = getComputedStyle(probe).getPropertyValue('--vt-bg').trim();
    probe.remove();

    return value || tokenResolver()('--vt-bg');
}

/** ASCII-szlag a fájlnévhez (ékezet nélkül, kötőjellel). */
export function slugify(value: string): string {
    const slug = value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);

    return slug || 'chart';
}

/** `YYYYMMDD` a helyi dátumból. */
export function stamp(date: Date = new Date()): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`;
}

/** dataURL → Blob → letöltés (`<a download>`), utána az objektum-URL felszabadul. */
export function downloadDataUrl(dataUrl: string, filename: string): void {
    const [head, body] = dataUrl.split(',', 2);
    const mime = /data:([^;]+)/.exec(head ?? '')?.[1] ?? 'application/octet-stream';
    const binary = atob(body ?? '');
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

    const url = URL.createObjectURL(new Blob([bytes], { type: mime }));
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.rel = 'noopener';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

export type ExportBackground = 'transparent' | 'dark';

export interface ExportableChart {
    toPng: (background: ExportBackground) => string | null;
}
