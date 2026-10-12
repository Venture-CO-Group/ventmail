/*
 | KLIENS-OLDALI ALLOWLIST-TISZTÍTÁS — a szerver `HtmlSanitizer`-ének tükre.
 |
 | Két okból kell a kliensen is, és egyik sem a biztonsági garancia (az a SZERVERÉ, a
 | kimeneten, minden mentésnél):
 |  1. a vásznon a felhasználó tartalmát `v-html`-lel jelenítjük meg — a MI originünkön. Egy
 |     beillesztett `<img onerror>` a szerkesztőben futna le, a mentés előtt;
 |  2. az inline szerkesztő (contenteditable) böngészőfüggő szemetet termel (`<font>`,
 |     `<o:p>`, Word-stílusok) — a tárolt alak legyen ugyanaz a szűk HTML, amit a szerver vár.
 |
 | A listák a szerverével AZONOSAK (`INLINE_TAGS`, `RICH_TAGS`, `BLOCK_TAGS`, attribútumok,
 | URL-szabály, tiltott stílus-minták). Ha az egyik változik, a másik is — a szerver a döntő.
 */

const INLINE_TAGS = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'span', 'br'];
const RICH_TAGS = ['p', 'div', 'ul', 'ol', 'li'];
const BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'hr', 'img', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'small', 'blockquote', 'center'];

const DROP_WITH_CONTENT = [
    'script', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'link', 'form', 'input', 'button', 'select', 'textarea',
    'frame', 'frameset', 'noscript', 'svg', 'math', 'template', 'applet', 'audio', 'video', 'source', 'title', 'head',
];

const ATTRIBUTES: Record<string, string[]> = {
    '*': ['style', 'class', 'title', 'dir', 'lang'],
    a: ['href', 'target', 'rel'],
    img: ['src', 'alt', 'width', 'height', 'border'],
    td: ['colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'bgcolor'],
    th: ['colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'bgcolor'],
    tr: ['align', 'valign', 'bgcolor'],
    table: ['width', 'align', 'border', 'cellpadding', 'cellspacing', 'bgcolor', 'role'],
    p: ['align'],
    div: ['align'],
};

const FORBIDDEN_STYLE = ['expression', 'javascript', 'vbscript', 'behavior', '@import', 'url(', '\\'];

const MERGE_TAG = /^\{\{\s*[A-Za-z0-9_.-]+\s*(?:\|\|\s*([^}]*?)\s*)?\}\}$/;

export type SanitizeProfile = 'inline' | 'rich' | 'block';

/** A szerver `UrlPolicy`-jának tükre: `https://`, `mailto:`, horgony vagy biztonságos merge-tag. */
export function isAllowedUrl(value: string): boolean {
    const trimmed = value.trim();

    if (trimmed === '') return false;

    const tag = MERGE_TAG.exec(trimmed);

    if (tag) {
        const fallback = (tag[1] ?? '').trim();

        return fallback === '' || isLiteralAllowed(fallback);
    }

    return isLiteralAllowed(trimmed);
}

function isLiteralAllowed(value: string): boolean {
    const lower = value.trim().toLowerCase();

    return lower.startsWith('#') || lower.startsWith('https://') || lower.startsWith('mailto:');
}

export function sanitizeHtml(html: string, profile: SanitizeProfile): string {
    if (html.trim() === '') return '';

    const allowed = profile === 'inline' ? INLINE_TAGS : profile === 'rich' ? [...INLINE_TAGS, ...RICH_TAGS] : [...INLINE_TAGS, ...BLOCK_TAGS];
    const doc = new DOMParser().parseFromString(`<body><div id="vm-root">${html}</div></body>`, 'text/html');
    const root = doc.getElementById('vm-root');

    if (!root) return '';

    walk(root, allowed);

    return root.innerHTML;
}

function walk(node: Element, allowed: string[]): void {
    // HÁTULRÓL ELŐRE: a törlés és a kibontás az élő `children` listát módosítja.
    for (let i = node.childNodes.length - 1; i >= 0; i--) {
        const child = node.childNodes[i];

        if (child.nodeType === Node.COMMENT_NODE) {
            child.remove();
            continue;
        }

        if (!(child instanceof Element)) continue;

        const tag = child.tagName.toLowerCase();

        if (DROP_WITH_CONTENT.includes(tag)) {
            child.remove();
            continue;
        }

        if (!allowed.includes(tag)) {
            walk(child, allowed);
            child.replaceWith(...Array.from(child.childNodes));
            continue;
        }

        cleanAttributes(child, tag);
        walk(child, allowed);
    }
}

function cleanAttributes(element: Element, tag: string): void {
    const allowed = [...ATTRIBUTES['*'], ...(ATTRIBUTES[tag] ?? [])];

    for (const attribute of Array.from(element.attributes)) {
        const name = attribute.name.toLowerCase();

        if (!allowed.includes(name)) {
            element.removeAttribute(attribute.name);
            continue;
        }

        if ((name === 'href' || name === 'src') && !isAllowedUrl(attribute.value)) {
            element.removeAttribute(attribute.name);
            continue;
        }

        if (name === 'style') {
            const style = cleanStyle(attribute.value);

            if (style === '') element.removeAttribute('style');
            else element.setAttribute('style', style);
        }

        if (name === 'target' && tag === 'a') {
            element.setAttribute('rel', 'noopener noreferrer');
        }
    }
}

function cleanStyle(value: string): string {
    return value
        .split(';')
        .map((declaration) => declaration.trim())
        .filter((declaration) => declaration !== '' && declaration.includes(':'))
        .filter((declaration) => !FORBIDDEN_STYLE.some((needle) => declaration.toLowerCase().includes(needle)))
        .join('; ');
}

/** Sima szöveg HTML-ből (a plaintext-előnézet és a címkék számára). */
export function textOf(html: string): string {
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');

    return (doc.body.textContent ?? '').trim();
}
