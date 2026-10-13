import type { Attrs, Block, BlockPath, DropTarget, LayoutDef } from './types';

/*
 | A BLOKKFA MŰVELETEI — tiszta függvények, a szerkesztő állapotgépe (D3).
 |
 | A fa-invariáns (gyökér → section → column → tartalom) itt őrződik: egy tartalmi blokk
 | soha nem kerül a gyökérbe, egy szakasz soha nem kerül oszlopba. Minden művelet ÚJ fát ad
 | (mély másolat) — így a visszavonás egyszerű pillanatkép-lista, és a Vue reaktivitása sem
 | csúszik el a helyben módosított tömbökön.
 */

export function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

/**
 * Normalizálás betöltéskor: a PHP az üres `attrs`-t `[]`-ként küldi, amit a kliens
 * objektumként kezelne — egy tömbre írt kulcs a JSON-ban ELVESZNE.
 */
export function normalize(blocks: unknown): Block[] {
    if (!Array.isArray(blocks)) return [];

    return blocks.map((raw) => normalizeBlock(raw as Block));
}

function normalizeBlock(block: Block): Block {
    const attrs = block.attrs && !Array.isArray(block.attrs) && typeof block.attrs === 'object' ? { ...block.attrs } : {};
    const out: Block = { type: String(block.type), attrs };

    if (Array.isArray(block.children)) {
        out.children = block.children.map((child) => normalizeBlock(child));
    }

    return out;
}

export function section(columns: string[] = ['100%'], attrs: Attrs = {}): Block {
    return {
        type: 'section',
        attrs,
        children: columns.map((width) => ({ type: 'column', attrs: columns.length > 1 ? { width } : {}, children: [] })),
    };
}

export function sectionFromLayout(layout: LayoutDef): Block {
    // A térköz a KATALÓGUSBÓL jön (a levél értéke, nem a felületé — lásd `EditorCatalog`).
    return section(layout.columns, layout.padding ? { padding: layout.padding } : {});
}

export function contentBlock(type: string, defaults: Attrs): Block {
    return { type, attrs: clone(defaults) };
}

export function getBlock(blocks: Block[], path: BlockPath): Block | undefined {
    return blocks[path.s]?.children?.[path.c]?.children?.[path.b];
}

export function columnChildren(blocks: Block[], s: number, c: number): Block[] | undefined {
    return blocks[s]?.children?.[c]?.children;
}

/** Tartalmi blokk beszúrása egy oszlop adott helyére. */
export function insertBlock(blocks: Block[], target: { s: number; c: number; index: number }, block: Block): Block[] {
    const next = clone(blocks);
    const column = next[target.s]?.children?.[target.c];

    if (!column) return next;

    column.children = column.children ?? [];
    column.children.splice(Math.max(0, Math.min(target.index, column.children.length)), 0, block);

    return next;
}

/** Szakasz beszúrása a gyökérbe. */
export function insertSection(blocks: Block[], index: number, block: Block): Block[] {
    const next = clone(blocks);

    next.splice(Math.max(0, Math.min(index, next.length)), 0, block);

    return next;
}

export function removeBlock(blocks: Block[], path: BlockPath): Block[] {
    const next = clone(blocks);

    next[path.s]?.children?.[path.c]?.children?.splice(path.b, 1);

    return next;
}

export function removeSection(blocks: Block[], s: number): Block[] {
    const next = clone(blocks);

    next.splice(s, 1);

    return next;
}

export function duplicateBlock(blocks: Block[], path: BlockPath): Block[] {
    const original = getBlock(blocks, path);

    return original ? insertBlock(blocks, { s: path.s, c: path.c, index: path.b + 1 }, clone(original)) : blocks;
}

export function duplicateSection(blocks: Block[], s: number): Block[] {
    const original = blocks[s];

    return original ? insertSection(blocks, s + 1, clone(original)) : blocks;
}

/**
 * Tartalmi blokk ÁTHELYEZÉSE (húzás a vásznon belül). A cél-index a FORRÁS kivétele előtti
 * fára vonatkozik — ugyanazon az oszlopon belül lefelé mozgatva eggyel csökken.
 */
export function moveBlock(blocks: Block[], from: BlockPath, to: { s: number; c: number; index: number }): { blocks: Block[]; path: BlockPath } {
    const moving = getBlock(blocks, from);

    if (!moving) return { blocks, path: from };

    let index = to.index;

    if (from.s === to.s && from.c === to.c && from.b < to.index) {
        index -= 1;
    }

    const next = insertBlock(removeBlock(blocks, from), { s: to.s, c: to.c, index }, clone(moving));

    return { blocks: next, path: { s: to.s, c: to.c, b: Math.max(0, Math.min(index, (columnChildren(next, to.s, to.c)?.length ?? 1) - 1)) } };
}

export function moveSection(blocks: Block[], from: number, to: number): { blocks: Block[]; index: number } {
    const moving = blocks[from];

    if (!moving) return { blocks, index: from };

    const index = from < to ? to - 1 : to;
    const next = insertSection(removeSection(blocks, from), index, clone(moving));

    return { blocks: next, index };
}

/** Billentyűzetes mozgatás: fel/le az oszlopon belül, a szélén átlépve a szomszéd szakaszba. */
export function nudgeBlock(blocks: Block[], path: BlockPath, delta: -1 | 1): { blocks: Block[]; path: BlockPath } {
    const siblings = columnChildren(blocks, path.s, path.c) ?? [];
    const target = path.b + delta;

    if (target >= 0 && target < siblings.length) {
        return moveBlock(blocks, path, { s: path.s, c: path.c, index: delta > 0 ? target + 1 : target });
    }

    const neighbour = path.s + delta;

    if (neighbour < 0 || neighbour >= blocks.length) {
        return { blocks, path };
    }

    const column = Math.min(path.c, (blocks[neighbour]?.children?.length ?? 1) - 1);
    const length = columnChildren(blocks, neighbour, column)?.length ?? 0;

    return moveBlock(blocks, path, { s: neighbour, c: column, index: delta > 0 ? 0 : length });
}

export function setAttrs(blocks: Block[], path: BlockPath, patch: Attrs): Block[] {
    const next = clone(blocks);
    const block = getBlock(next, path);

    if (block) {
        block.attrs = cleanAttrs({ ...(block.attrs ?? {}), ...patch });
    }

    return next;
}

export function setSectionAttrs(blocks: Block[], s: number, patch: Attrs): Block[] {
    const next = clone(blocks);
    const target = next[s];

    if (target) {
        target.attrs = cleanAttrs({ ...(target.attrs ?? {}), ...patch });
    }

    return next;
}

/** Egy szakasz oszlop-elrendezésének cseréje — a meglévő tartalom megmarad. */
export function setSectionLayout(blocks: Block[], s: number, layout: LayoutDef): Block[] {
    const next = clone(blocks);
    const target = next[s];

    if (!target) return next;

    const columns = target.children ?? [];
    const content = columns.map((column) => column.children ?? []);
    const widths = layout.columns;

    target.children = widths.map((width, index) => ({
        type: 'column',
        attrs: widths.length > 1 ? { width } : {},
        children: index < widths.length - 1 ? (content[index] ?? []) : content.slice(index).flat(),
    }));

    return next;
}

/** Az `undefined` / üres értékű kulcsok kidobása: a szerver az ismeretlen mezőt hibának veszi. */
function cleanAttrs(attrs: Attrs): Attrs {
    const out: Attrs = {};

    for (const [key, value] of Object.entries(attrs)) {
        if (value === undefined || value === null || value === '') continue;
        out[key] = value;
    }

    return out;
}

export function sameTarget(a: DropTarget, b: DropTarget): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

/** A blokk útvonal szöveges alakja (`s.c.b`) — a teszt-azonosítókhoz és az optimalizáló riporthoz. */
export function pathKey(path: BlockPath): string {
    return `${path.s}.${path.c}.${path.b}`;
}

/** Az optimalizáló riport útvonala (`0.1.2`) → blokk-útvonal. */
export function parsePath(value: string): BlockPath | null {
    const parts = value.split('.').map((part) => Number(part));

    if (parts.length !== 3 || parts.some((part) => Number.isNaN(part))) return null;

    return { s: parts[0], c: parts[1], b: parts[2] };
}

/** Bejárás: minden tartalmi blokk az útvonalával. */
export function eachBlock(blocks: Block[], visit: (block: Block, path: BlockPath) => void): void {
    blocks.forEach((sec, s) => {
        (sec.children ?? []).forEach((column, c) => {
            (column.children ?? []).forEach((block, b) => visit(block, { s, c, b }));
        });
    });
}
