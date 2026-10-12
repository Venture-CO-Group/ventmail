import type { Attrs, EditorCatalog, TemplateStyles } from './types';

/*
 | A VÁSZON MEGJELENÍTÉSI SEGÉDEI — a blokk-adatból (a levél értékeiből) inline stílus.
 |
 | Ez KÖZELÍTÉS: a vászon a szerkesztésé, az IGAZSÁG a szerver fordítója (az Előnézet azt
 | mutatja). A számok (címsor-méretek) a `MjmlWriter` alapértékei; mértékegységet itt
 | csak sablon-literálban fűzünk hozzá, beégetett hossz nincs.
 */

const HEADING_SIZES = [0, 30, 24, 20, 18];

export type Resolved = Required<TemplateStyles>;

export function resolveStyles(catalog: EditorCatalog, styles: TemplateStyles): Resolved {
    return { ...catalog.styleDefaults, ...styles } as Resolved;
}

function unit(value: unknown, fallback: number): string {
    const number = Number(value);

    return `${Number.isFinite(number) && number > 0 ? number : fallback}px`;
}

/** Egy `padding` attribútum (a levél értéke, pl. két hossz) — csak a szigorú alakot engedjük át. */
const PADDING = /^(\d{1,3}[a-z]{2}\s*){1,4}$/;

export function padding(value: unknown): string | undefined {
    return typeof value === 'string' && PADDING.test(value.trim()) ? value.trim() : undefined;
}

export type Align = 'left' | 'center' | 'right' | 'justify';

export function align(attrs: Attrs, fallback: Align = 'left'): Align {
    const value = String(attrs.align ?? fallback) as Align;

    return ['left', 'center', 'right', 'justify'].includes(value) ? value : fallback;
}

export function textStyle(attrs: Attrs, styles: Resolved, heading = false): Record<string, string | undefined> {
    const level = Math.max(1, Math.min(4, Number(attrs.level ?? 2)));
    const size = heading ? unit(attrs.font_size, HEADING_SIZES[level]) : unit(attrs.font_size, styles.font_size);

    return {
        fontSize: size,
        lineHeight: heading ? '1.3' : String(attrs.line_height ?? '1.5'),
        fontWeight: heading ? '700' : undefined,
        color: String(attrs.color ?? (heading ? styles.heading_color : styles.text_color)),
        textAlign: align(attrs),
        // Az alapértelmezett térköz a vásznon TOKEN (közelítés; a levélbeli érték a fordítóé).
        padding: padding(attrs.padding) ?? 'var(--vt-space-2) 0',
    };
}

export function buttonStyle(attrs: Attrs, styles: Resolved): Record<string, string> {
    return {
        display: 'inline-block',
        padding: 'var(--vt-space-3) var(--vt-space-6)',
        borderRadius: unit(attrs.border_radius, styles.button_radius),
        background: String(attrs.background ?? attrs.button_background ?? styles.button_background),
        color: String(attrs.color ?? attrs.button_color ?? styles.button_color),
        fontWeight: '700',
        textDecoration: 'none',
    };
}

/** A saját originünkre mutató képet relatív úton töltjük be (fejlesztésben `http`-n fut a szerver). */
export function displayUrl(src: unknown, catalog: EditorCatalog): string {
    const value = String(src ?? '');

    if (catalog.assetBase && value.startsWith(`${catalog.assetBase}/`)) {
        return value.slice(catalog.assetBase.length);
    }

    return value;
}

export function imageWidth(attrs: Attrs, fallback?: number): string | undefined {
    const raw = attrs.width ?? fallback;

    if (raw === undefined || raw === null || raw === '') return undefined;

    const value = String(raw).trim();

    return /%$/.test(value) ? value : unit(value, 600);
}
