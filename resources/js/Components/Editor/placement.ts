import { clone, getBlock, insertBlock, insertSection, moveBlock, moveSection, removeBlock, section } from './tree';
import type { Block, DragPayload, DropTarget, Selection } from './types';

/*
 | BESZÚRÁS ÉS HÚZÁS ELHELYEZÉSE — a `Template/Edit.vue`-ból kiemelve (unit-tesztelt).
 |
 | Ez őrzi a fészkelési szabályt a beszúrásnál: a gyökérbe csak szakasz kerül (a magányos
 | tartalmi blokk egyoszlopos szakaszba csomagolódik), szakasz soha nem kerül oszlopba, és a
 | kötelező lábléc a levél alján marad. Minden függvény ÚJ fát ad + a beszúrt elem kijelölését.
 */

export type Resolved = { kind: 'section' | 'block'; block: Block };

export interface Placement {
    blocks: Block[];
    selection: Selection;
}

/** A lábléc-szakasz ELÉ szúrunk, ha a levél végén lábléc áll — a kötelező elem maradjon alul. */
export function appendIndex(blocks: Block[]): number {
    const last = blocks[blocks.length - 1];
    const lastHasFooter = (last?.children ?? []).some((column) => (column.children ?? []).some((block) => ['footer', 'unsubscribe'].includes(block.type)));

    return lastHasFooter ? blocks.length - 1 : blocks.length;
}

/** Egy tartalmi blokk egyoszlopos szakaszba csomagolva (a gyökérbe csak szakasz kerülhet). */
export function wrapInSection(block: Block): Block {
    const wrapper = section();
    wrapper.children![0].children = [block];

    return wrapper;
}

/** KATTINTÁSSAL / ENTERREL beszúrás (a húzás billentyűzetes alternatívája): a kijelölés után. */
export function placeAdd(blocks: Block[], resolved: Resolved, selection: Selection): Placement {
    if (resolved.kind === 'section') {
        const index = selection ? selection.s + 1 : appendIndex(blocks);

        return { blocks: insertSection(blocks, index, resolved.block), selection: { kind: 'section', s: index } };
    }

    if (selection?.kind === 'block') {
        return {
            blocks: insertBlock(blocks, { s: selection.s, c: selection.c, index: selection.b + 1 }, resolved.block),
            selection: { kind: 'block', s: selection.s, c: selection.c, b: selection.b + 1 },
        };
    }

    if (selection?.kind === 'section') {
        const length = blocks[selection.s]?.children?.[0]?.children?.length ?? 0;

        return { blocks: insertBlock(blocks, { s: selection.s, c: 0, index: length }, resolved.block), selection: { kind: 'block', s: selection.s, c: 0, b: length } };
    }

    const index = appendIndex(blocks);

    return { blocks: insertSection(blocks, index, wrapInSection(resolved.block)), selection: { kind: 'block', s: index, c: 0, b: 0 } };
}

/**
 * HÚZÁS vége. `resolve` a palettáról / mintából / mentett blokkból húzott elemet oldja fel
 * (a vásznon belüli mozgatásnál nem hívódik). `null` = nincs teendő (érvénytelen cél).
 */
export function placeDrop(blocks: Block[], payload: DragPayload, dropTarget: DropTarget, resolve: () => Resolved | null): Placement | null {
    if (!dropTarget) return null;

    if (payload.source === 'canvas-block') {
        if (dropTarget.kind === 'block') {
            const moved = moveBlock(blocks, payload.path, dropTarget);

            return { blocks: moved.blocks, selection: { kind: 'block', ...moved.path } };
        }

        const block = getBlock(blocks, payload.path);

        if (!block) return null;

        const inserted = insertSection(blocks, dropTarget.index, wrapInSection(clone(block)));
        const shifted = payload.path.s >= dropTarget.index ? { ...payload.path, s: payload.path.s + 1 } : payload.path;

        return { blocks: removeBlock(inserted, shifted), selection: { kind: 'block', s: dropTarget.index, c: 0, b: 0 } };
    }

    if (payload.source === 'canvas-section') {
        // Szakasz csak a gyökérbe — oszlopba nem fészkelhető.
        if (dropTarget.kind !== 'section') return null;

        const moved = moveSection(blocks, payload.s, dropTarget.index);

        return { blocks: moved.blocks, selection: { kind: 'section', s: moved.index } };
    }

    const resolved = resolve();

    if (!resolved) return null;

    if (resolved.kind === 'block' && dropTarget.kind === 'block') {
        return { blocks: insertBlock(blocks, dropTarget, resolved.block), selection: { kind: 'block', s: dropTarget.s, c: dropTarget.c, b: dropTarget.index } };
    }

    // Szakasz blokk-célra: a cél-szakasz UTÁN; tartalmi blokk szakasz-célra: becsomagolva.
    const index = dropTarget.kind === 'section' ? dropTarget.index : dropTarget.s + 1;
    const sectionBlock = resolved.kind === 'block' ? wrapInSection(resolved.block) : resolved.block;

    return {
        blocks: insertSection(blocks, index, sectionBlock),
        selection: resolved.kind === 'block' ? { kind: 'block', s: index, c: 0, b: 0 } : { kind: 'section', s: index },
    };
}
