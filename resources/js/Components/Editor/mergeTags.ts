import type { MergeTagDef } from './types';

/*
 | A MERGE-TAG SZINTAXIS — a SAJÁT alakunk, amit a szerver `TemplateRenderer`-e ért:
 | `{{ contact.first_name || Kedves vendégünk }}`. A fallback a hiányzó vagy üres érték
 | helyére kerül; a link-tagek (leiratkozás, böngészős nézet) fallback nélkül állnak.
 */

export function mergeTag(key: string, fallback = ''): string {
    const clean = fallback.replace(/[{}|<>"]/g, '').trim();

    return clean === '' ? `{{ ${key} }}` : `{{ ${key} || ${clean} }}`;
}

/** A link-tag `<a>` eleme (a böngészős nézet és a leiratkozó link szövegével). */
export function mergeLink(tag: MergeTagDef, label: string): string {
    const text = label.replace(/[<>&"]/g, '');

    return `<a href="${mergeTag(tag.key)}">${text}</a>`;
}
