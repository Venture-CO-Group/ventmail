import { describe, expect, it, vi } from 'vitest';
import { appendIndex, placeAdd, placeDrop, type Resolved } from '../../../resources/js/Components/Editor/placement';
import { section } from '../../../resources/js/Components/Editor/tree';
import type { Block } from '../../../resources/js/Components/Editor/types';

/*
 | Beszúrás kattintással és húzással: a FÉSZKELÉSI SZABÁLY (a gyökérbe csak szakasz, szakasz
 | nem kerül oszlopba), a lábléc alul tartása, és a beszúrt elem kijelölése.
 */

function block(type: string, content = type): Block {
    return { type, attrs: { content } };
}

function sectionWith(...items: Block[]): Block {
    const sec = section();
    sec.children![0].children = items;

    return sec;
}

function types(blocks: Block[]): string[][] {
    return blocks.map((sec) => {
        expect(sec.type).toBe('section');

        return (sec.children ?? []).flatMap((column) => {
            expect(column.type).toBe('column');

            return (column.children ?? []).map((child) => String(child.attrs?.content ?? child.type));
        });
    });
}

const button: Resolved = { kind: 'block', block: block('button', 'BTN') };
const twoColumns: Resolved = { kind: 'section', block: section(['50%', '50%']) };

describe('appendIndex', () => {
    it('a lábléc (footer / unsubscribe) elé szúr, egyébként a végére', () => {
        expect(appendIndex([])).toBe(0);
        expect(appendIndex([sectionWith(block('text'))])).toBe(1);
        expect(appendIndex([sectionWith(block('text')), sectionWith(block('footer'))])).toBe(1);
        expect(appendIndex([sectionWith(block('text')), sectionWith(block('unsubscribe'))])).toBe(1);
    });
});

describe('placeAdd (kattintás / Enter)', () => {
    const docBlocks = () => [sectionWith(block('text', 'T1'), block('text', 'T2')), sectionWith(block('footer', 'F'))];

    it('kijelölés nélkül a tartalmi blokk SAJÁT szakaszba kerül, a lábléc elé', () => {
        const placed = placeAdd(docBlocks(), button, null);

        expect(types(placed.blocks)).toEqual([['T1', 'T2'], ['BTN'], ['F']]);
        expect(placed.selection).toEqual({ kind: 'block', s: 1, c: 0, b: 0 });
    });

    it('kijelölt blokk után szúr, ugyanabba az oszlopba', () => {
        const placed = placeAdd(docBlocks(), button, { kind: 'block', s: 0, c: 0, b: 0 });

        expect(types(placed.blocks)).toEqual([['T1', 'BTN', 'T2'], ['F']]);
        expect(placed.selection).toEqual({ kind: 'block', s: 0, c: 0, b: 1 });
    });

    it('kijelölt szakasznál az első oszlop végére szúr', () => {
        const placed = placeAdd(docBlocks(), button, { kind: 'section', s: 0 });

        expect(types(placed.blocks)).toEqual([['T1', 'T2', 'BTN'], ['F']]);
        expect(placed.selection).toEqual({ kind: 'block', s: 0, c: 0, b: 2 });
    });

    it('szakasz a kijelölt szakasz UTÁN, kijelölés nélkül a lábléc elé — soha nem oszlopba', () => {
        const afterSelection = placeAdd(docBlocks(), twoColumns, { kind: 'block', s: 0, c: 0, b: 1 });
        expect(afterSelection.blocks).toHaveLength(3);
        expect(afterSelection.blocks[1].children).toHaveLength(2);
        expect(afterSelection.selection).toEqual({ kind: 'section', s: 1 });

        const appended = placeAdd(docBlocks(), twoColumns, null);
        expect(appended.selection).toEqual({ kind: 'section', s: 1 });
        expect(types(appended.blocks)[2]).toEqual(['F']);
    });
});

describe('placeDrop (húzás)', () => {
    const docBlocks = () => [sectionWith(block('text', 'A'), block('text', 'B')), sectionWith(block('text', 'C'))];
    const never = () => {
        throw new Error('a vásznon belüli mozgatás nem old fel palettát');
    };

    it('palettáról blokk-célra: oda szúr, és kijelöli', () => {
        const placed = placeDrop(docBlocks(), { source: 'palette', id: 'button' }, { kind: 'block', s: 1, c: 0, index: 0 }, () => button);

        expect(types(placed!.blocks)).toEqual([['A', 'B'], ['BTN', 'C']]);
        expect(placed!.selection).toEqual({ kind: 'block', s: 1, c: 0, b: 0 });
    });

    it('palettáról szakasz-célra: a tartalmi blokk becsomagolva kerül a gyökérbe', () => {
        const placed = placeDrop(docBlocks(), { source: 'palette', id: 'button' }, { kind: 'section', index: 0 }, () => button);

        expect(types(placed!.blocks)).toEqual([['BTN'], ['A', 'B'], ['C']]);
        expect(placed!.selection).toEqual({ kind: 'block', s: 0, c: 0, b: 0 });
    });

    it('layout blokk-célra: NEM fészkelődik oszlopba — a cél-szakasz után kerül', () => {
        const placed = placeDrop(docBlocks(), { source: 'layout', key: 'two' }, { kind: 'block', s: 0, c: 0, index: 1 }, () => twoColumns);

        expect(placed!.blocks).toHaveLength(3);
        expect(placed!.blocks[1].children).toHaveLength(2);
        expect(types(placed!.blocks)[0]).toEqual(['A', 'B']);
        expect(placed!.selection).toEqual({ kind: 'section', s: 1 });
    });

    it('vásznon belüli blokk-mozgatás blokk-célra', () => {
        const placed = placeDrop(docBlocks(), { source: 'canvas-block', path: { s: 0, c: 0, b: 0 } }, { kind: 'block', s: 1, c: 0, index: 1 }, never);

        expect(types(placed!.blocks)).toEqual([['B'], ['C', 'A']]);
        expect(placed!.selection).toEqual({ kind: 'block', s: 1, c: 0, b: 1 });
    });

    it('vásznon belüli blokk szakasz-célra: új szakaszba kerül, a forrás helyéről eltűnik (index-eltolással)', () => {
        const placed = placeDrop(docBlocks(), { source: 'canvas-block', path: { s: 1, c: 0, b: 0 } }, { kind: 'section', index: 0 }, never);

        expect(types(placed!.blocks)).toEqual([['C'], ['A', 'B'], []]);
        expect(placed!.selection).toEqual({ kind: 'block', s: 0, c: 0, b: 0 });
    });

    it('szakasz csak szakasz-célra mozdul; blokk-célra elutasítva', () => {
        const moved = placeDrop(docBlocks(), { source: 'canvas-section', s: 0 }, { kind: 'section', index: 2 }, never);
        expect(types(moved!.blocks)).toEqual([['C'], ['A', 'B']]);
        expect(moved!.selection).toEqual({ kind: 'section', s: 1 });

        expect(placeDrop(docBlocks(), { source: 'canvas-section', s: 0 }, { kind: 'block', s: 1, c: 0, index: 0 }, never)).toBeNull();
    });

    it('nincs cél, ismeretlen forrás vagy eltűnt blokk: nincs teendő', () => {
        const resolve = vi.fn(() => null);

        expect(placeDrop(docBlocks(), { source: 'palette', id: 'x' }, null, resolve)).toBeNull();
        expect(resolve).not.toHaveBeenCalled();
        expect(placeDrop(docBlocks(), { source: 'saved', uuid: 'gone' }, { kind: 'section', index: 0 }, resolve)).toBeNull();
        expect(placeDrop(docBlocks(), { source: 'canvas-block', path: { s: 0, c: 0, b: 9 } }, { kind: 'section', index: 0 }, never)).toBeNull();
    });

    it('a bemeneti fa érintetlen marad', () => {
        const blocks = docBlocks();
        const frozen = JSON.stringify(blocks);

        placeDrop(blocks, { source: 'canvas-block', path: { s: 0, c: 0, b: 0 } }, { kind: 'section', index: 2 }, never);
        placeAdd(blocks, button, null);

        expect(JSON.stringify(blocks)).toBe(frozen);
    });
});
