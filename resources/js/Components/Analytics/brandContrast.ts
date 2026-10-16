/*
 | ÉLŐ KONTRASZT-JELZÉS a branding-űrlapon (v1.1 · 8. modul, D236). A DÖNTÉS a szerveré
 | (`BrandColor`); ez csak a gépelés közbeni tipp. A referenciaszínek a tokenek SZÁMÍTOTT értékei
 | (`--vt-color-neutral-0`, `--vt-color-neutral-900`, sötét `--vt-bg-base`) — nincs beégetett szín.
 */

const HEX = /^#?([0-9a-f]{6})$/i;

export function isHexColor(value: string): boolean {
    return HEX.test(value.trim()) && value.trim().startsWith('#');
}

function channels(value: string): [number, number, number] | null {
    const hex = HEX.exec(value.trim());

    if (hex) {
        const n = parseInt(hex[1], 16);

        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    const rgb = /rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)/i.exec(value);

    return rgb ? [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])] : null;
}

function luminance(value: string): number | null {
    const c = channels(value);

    if (!c) return null;

    const [r, g, b] = c.map((v) => {
        const s = v / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a: string, b: string): number | null {
    const la = luminance(a);
    const lb = luminance(b);

    if (la === null || lb === null) return null;

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

export interface ContrastVerdict {
    text: 'light' | 'dark' | null;
    ratio: number;
    weakOnDark: boolean;
}

export function verdict(color: string, light: string, dark: string, darkSurface: string): ContrastVerdict | null {
    const onLight = contrast(color, light);
    const onDark = contrast(color, dark);
    const surface = contrast(color, darkSurface);

    if (onLight === null || onDark === null) return null;

    const text = onLight >= 4.5 ? 'light' : onDark >= 4.5 ? 'dark' : null;

    return { text, ratio: Math.round(Math.max(onLight, onDark) * 100) / 100, weakOnDark: surface !== null && surface < 3 };
}
