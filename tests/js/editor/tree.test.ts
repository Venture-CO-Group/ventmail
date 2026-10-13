import { describe, expect, it } from 'vitest';
import {
    contentBlock,
    duplicateBlock,
    duplicateSection,
    eachBlock,
    getBlock,
    insertBlock,
    insertSection,
    moveBlock,
    moveSection,
    normalize,
    nudgeBlock,
    parsePath,
    pathKey,
    removeBlock,
    removeSection,
    section,
    sectionFromLayout,
    setAttrs,
    setSectionAttrs,
    setSectionLayout,
} from '../../../resources/js/Components/Editor/tree';
import type { Block } from '../../../resources/js/Components/Editor/types';

/*
 | A blokkfa tiszta műveletei (D3): beszúrás, mozgatás, törlés, duplikálás, fészkelési
 | invariáns (gyökér → section → column → tartalom), immutabilitás és a szerver felé menő
 | JSON-alak.
 */

function text(content: string): Block {
    return { type: 'text', attrs: { content } };
}

/** Két szakasz: az első kétoszlopos (A,B | C), a második egyoszlopos (D). */
function doc(): Block[] {
    const first = section(['50%', '50%']);
    first.children![0].children = [text('A'), text('B')];
    first.children![1].children = [text('C')];

    const second = section();
    second.children![0].children = [text('D')];

    return [first, second];
}

function contents(blocks: Block[]): string[][][] {
    return blocks.map((sec) => (sec.children ?? []).map((column) => (column.children ?? []).map((block) => String(block.attrs?.content))));
}

describe('section / layout', () => {
    it('egyoszlopos szakasz: egy oszlop, szélesség nélkül', () => {
        expect(section()).toEqual({ type: 'section', attrs: {}, children: [{ type: 'column', attrs: {}, children: [] }] });
    });

    it('többoszlopos szakasz: oszloponként szélesség', () => {
        const block = section(['33%', '67%']);

        expect(block.children?.map((column) => column.attrs)).toEqual([{ width: '33%' }, { width: '67%' }]);
        expect(block.children?.every((column) => column.type === 'column')).toBe(true);
    });

    it('a layout térköze a katalógusból jön', () => {
        expect(sectionFromLayout({ key: 'two', columns: ['50%', '50%'], padding: 'p' }).attrs).toEqual({ padding: 'p' });
        expect(sectionFromLayout({ key: 'one', columns: ['100%'] }).attrs).toEqual({});
    });

    it('a tartalmi blokk az alapértékek MÁSOLATÁT kapja', () => {
        const defaults = { content: 'x', nested: { a: 1 } };
        const block = contentBlock('text', defaults);

        (block.attrs!.nested as { a: number }).a = 2;

        expect(defaults.nested.a).toBe(1);
    });
});

describe('insert / remove', () => {
    it('tartalmi blokkot a megadott oszlop-indexre szúr', () => {
        const next = insertBlock(doc(), { s: 0, c: 0, index: 1 }, text('X'));

        expect(contents(next)[0][0]).toEqual(['A', 'X', 'B']);
    });

    it('a túlfutó indexet az oszlop végére szorítja, a negatívat az elejére', () => {
        expect(contents(insertBlock(doc(), { s: 1, c: 0, index: 99 }, text('X')))[1][0]).toEqual(['D', 'X']);
        expect(contents(insertBlock(doc(), { s: 1, c: 0, index: -5 }, text('X')))[1][0]).toEqual(['X', 'D']);
    });

    it('nem létező oszlopba nem szúr (a fa változatlan)', () => {
        expect(insertBlock(doc(), { s: 7, c: 0, index: 0 }, text('X'))).toEqual(doc());
    });

    it('szakaszt a gyökérbe szúr', () => {
        const next = insertSection(doc(), 1, section());

        expect(next).toHaveLength(3);
        expect(next[1].children?.[0].children).toEqual([]);
    });

    it('blokkot és szakaszt töröl', () => {
        expect(contents(removeBlock(doc(), { s: 0, c: 0, b: 0 }))[0][0]).toEqual(['B']);
        expect(contents(removeSection(doc(), 0))).toEqual([[['D']]]);
    });

    it('minden művelet ÚJ fát ad — a bemenet érintetlen (a visszavonás pillanatképei erre épülnek)', () => {
        const original = doc();
        const frozen = JSON.stringify(original);

        insertBlock(original, { s: 0, c: 0, index: 0 }, text('X'));
        removeBlock(original, { s: 0, c: 0, b: 0 });
        moveBlock(original, { s: 0, c: 0, b: 0 }, { s: 1, c: 0, index: 0 });
        setAttrs(original, { s: 0, c: 0, b: 0 }, { content: 'Z' });
        duplicateSection(original, 0);

        expect(JSON.stringify(original)).toBe(frozen);
    });
});

describe('duplicate', () => {
    it('a blokk másolata közvetlenül utána kerül, független példányként', () => {
        const next = duplicateBlock(doc(), { s: 0, c: 0, b: 0 });

        expect(contents(next)[0][0]).toEqual(['A', 'A', 'B']);

        next[0].children![0].children![1].attrs!.content = 'changed';
        expect(next[0].children![0].children![0].attrs!.content).toBe('A');
    });

    it('a szakasz másolata utána kerül', () => {
        expect(contents(duplicateSection(doc(), 1))).toEqual([[['A', 'B'], ['C']], [['D']], [['D']]]);
    });

    it('nem létező elem duplikálása nem csinál semmit', () => {
        const blocks = doc();

        expect(duplicateBlock(blocks, { s: 0, c: 0, b: 9 })).toBe(blocks);
        expect(duplicateSection(blocks, 9)).toBe(blocks);
    });
});

describe('move', () => {
    it('ugyanazon oszlopon belül lefelé: a cél-index a kivétel előtti fára vonatkozik', () => {
        const moved = moveBlock(doc(), { s: 0, c: 0, b: 0 }, { s: 0, c: 0, index: 2 });

        expect(contents(moved.blocks)[0][0]).toEqual(['B', 'A']);
        expect(moved.path).toEqual({ s: 0, c: 0, b: 1 });
    });

    it('másik oszlopba és másik szakaszba', () => {
        const toColumn = moveBlock(doc(), { s: 0, c: 0, b: 1 }, { s: 0, c: 1, index: 0 });
        expect(contents(toColumn.blocks)[0]).toEqual([['A'], ['B', 'C']]);
        expect(toColumn.path).toEqual({ s: 0, c: 1, b: 0 });

        const toSection = moveBlock(doc(), { s: 0, c: 1, b: 0 }, { s: 1, c: 0, index: 1 });
        expect(contents(toSection.blocks)).toEqual([[['A', 'B'], []], [['D', 'C']]]);
        expect(toSection.path).toEqual({ s: 1, c: 0, b: 1 });
    });

    it('nem létező forrás: változatlan fa', () => {
        const blocks = doc();

        expect(moveBlock(blocks, { s: 0, c: 0, b: 5 }, { s: 1, c: 0, index: 0 })).toEqual({ blocks, path: { s: 0, c: 0, b: 5 } });
    });

    it('szakasz mozgatása le és fel', () => {
        const down = moveSection(doc(), 0, 2);
        expect(contents(down.blocks)).toEqual([[['D']], [['A', 'B'], ['C']]]);
        expect(down.index).toBe(1);

        const up = moveSection(down.blocks, 1, 0);
        expect(contents(up.blocks)).toEqual(contents(doc()));
        expect(up.index).toBe(0);
    });

    it('billentyűzetes mozgatás: oszlopon belül, majd a szélén át a szomszéd szakaszba', () => {
        const down = nudgeBlock(doc(), { s: 0, c: 0, b: 0 }, 1);
        expect(contents(down.blocks)[0][0]).toEqual(['B', 'A']);
        expect(down.path).toEqual({ s: 0, c: 0, b: 1 });

        // A első oszlop alja → a következő szakasz ELEJÉRE.
        const across = nudgeBlock(down.blocks, down.path, 1);
        expect(contents(across.blocks)).toEqual([[['B'], ['C']], [['A', 'D']]]);
        expect(across.path).toEqual({ s: 1, c: 0, b: 0 });

        // Vissza fel: az előző szakasz (azonos/utolsó oszlop) VÉGÉRE.
        const back = nudgeBlock(across.blocks, across.path, -1);
        expect(contents(back.blocks)).toEqual([[['B', 'A'], ['C']], [['D']]]);
    });

    it('a dokumentum szélén a billentyűzetes mozgatás nem csinál semmit', () => {
        const blocks = doc();

        expect(nudgeBlock(blocks, { s: 0, c: 0, b: 0 }, -1)).toEqual({ blocks, path: { s: 0, c: 0, b: 0 } });
        expect(nudgeBlock(blocks, { s: 1, c: 0, b: 0 }, 1)).toEqual({ blocks, path: { s: 1, c: 0, b: 0 } });
    });
});

describe('attribútumok és elrendezés', () => {
    it('a patch összeolvad, az üres / null / undefined kulcs kiesik (a szerver az ismeretlen mezőt hibának veszi)', () => {
        const next = setAttrs(doc(), { s: 0, c: 0, b: 0 }, { align: 'center', content: '', extra: null, other: undefined });

        expect(getBlock(next, { s: 0, c: 0, b: 0 })?.attrs).toEqual({ align: 'center' });
    });

    it('szakasz-attribútum', () => {
        expect(setSectionAttrs(doc(), 1, { background: 'token' })[1].attrs).toEqual({ background: 'token' });
    });

    it('az elrendezés cseréje megtartja a tartalmat; kevesebb oszlopnál az utolsó gyűjti a maradékot', () => {
        const one = setSectionLayout(doc(), 0, { key: 'one', columns: ['100%'] });
        expect(contents(one)[0]).toEqual([['A', 'B', 'C']]);
        expect(one[0].children?.[0].attrs).toEqual({});

        const three = setSectionLayout(doc(), 0, { key: 'three', columns: ['33%', '33%', '34%'] });
        expect(contents(three)[0]).toEqual([['A', 'B'], ['C'], []]);
        expect(three[0].children?.map((column) => column.attrs?.width)).toEqual(['33%', '33%', '34%']);
    });
});

describe('szerializálás és útvonalak', () => {
    it('normalize: a PHP üres `attrs: []` tömbje objektum lesz, és a típus szöveg', () => {
        const raw = [{ type: 'section', attrs: [], children: [{ type: 'column', attrs: [], children: [{ type: 'text', attrs: { content: 'hi' } }] }] }];
        const blocks = normalize(raw);

        expect(blocks[0].attrs).toEqual({});
        expect(Array.isArray(blocks[0].attrs)).toBe(false);

        // A tömbre írt kulcs a JSON-ban elveszne — objektumon megmarad.
        blocks[0].attrs!.padding = 'p';
        expect(JSON.parse(JSON.stringify(blocks))[0].attrs).toEqual({ padding: 'p' });
    });

    it('normalize: nem-tömb bemenet → üres fa', () => {
        expect(normalize(null)).toEqual([]);
        expect(normalize({ type: 'section' })).toEqual([]);
    });

    it('a fa JSON-alakja a szerver block-tree kontraktusa: csak type / attrs / children', () => {
        const json = JSON.parse(JSON.stringify(doc())) as Block[];

        const keys = new Set<string>();
        const walk = (block: Block) => {
            Object.keys(block).forEach((key) => keys.add(key));
            (block.children ?? []).forEach(walk);
        };
        json.forEach(walk);

        expect([...keys].sort()).toEqual(['attrs', 'children', 'type']);
        expect(json.every((sec) => sec.type === 'section' && sec.children?.every((column) => column.type === 'column'))).toBe(true);
    });

    it('pathKey ↔ parsePath oda-vissza, a hibás útvonal null', () => {
        expect(parsePath(pathKey({ s: 2, c: 1, b: 0 }))).toEqual({ s: 2, c: 1, b: 0 });
        expect(parsePath('1.2')).toBeNull();
        expect(parsePath('a.b.c')).toBeNull();
    });

    it('eachBlock minden tartalmi blokkot bejár az útvonalával', () => {
        const seen: string[] = [];

        eachBlock(doc(), (block, path) => seen.push(`${pathKey(path)}=${String(block.attrs?.content)}`));

        expect(seen).toEqual(['0.0.0=A', '0.0.1=B', '0.1.0=C', '1.0.0=D']);
    });
});
