import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { useEditor } from '../../../resources/js/Components/Editor/useEditor';
import { insertBlock, section } from '../../../resources/js/Components/Editor/tree';
import type { Block, TemplateData } from '../../../resources/js/Components/Editor/types';
import { withSetup } from '../support/withSetup';

/*
 | A szerkesztő állapota: visszavonás / újra (pillanatkép, gépelés-összevonás, korlát),
 | az automatikus mentés piszkos-állapota (debounce, párhuzamos mentés nincs, hiba), a
 | szerver felé menő payload és a CSAK OLVASHATÓ mód.
 */

const put = vi.hoisted(() => vi.fn());

vi.mock('axios', () => ({ default: { put }, AxiosError: class extends Error {} }));

function template(overrides: Partial<TemplateData> = {}): TemplateData {
    const sec = section();
    sec.children![0].children = [{ type: 'text', attrs: { content: 'Hello' } }];

    return {
        uuid: 'tpl-1',
        name: 'Welcome',
        subject: 'Hi',
        preheader: null,
        marketing: false,
        blocks: [sec],
        styles: null,
        updatedAt: '2026-10-01T10:00:00Z',
        ...overrides,
    };
}

function withText(blocks: Block[], content: string): Block[] {
    return insertBlock(blocks, { s: 0, c: 0, index: 99 }, { type: 'text', attrs: { content } });
}

function texts(blocks: Block[]): string[] {
    return (blocks[0].children?.[0].children ?? []).map((block) => String(block.attrs?.content));
}

beforeEach(() => {
    vi.useFakeTimers();
    put.mockReset();
    put.mockResolvedValue({ data: { savedAt: '2026-10-02T10:00:00Z', version: 3 } });
});

afterEach(() => {
    vi.useRealTimers();
});

describe('betöltés', () => {
    it('normalizálja a szerver adatát: a PHP üres `attrs: []` / `styles: []` objektum lesz', () => {
        const { result } = withSetup(() =>
            useEditor(template({ blocks: [{ type: 'section', attrs: [] as never, children: [] }], styles: [] as never, subject: null as never })),
        );

        expect(result.blocks.value[0].attrs).toEqual({});
        expect(result.styles.value).toEqual({});
        expect(result.subject.value).toBe('');
        expect(result.saveState.value).toBe('saved');
        expect(result.canUndo.value).toBe(false);
        expect(result.canRedo.value).toBe(false);
    });
});

describe('visszavonás / újra', () => {
    it('setBlocks → undo → redo visszaadja az állapotokat', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'Second'));
        expect(texts(result.blocks.value)).toEqual(['Hello', 'Second']);
        expect(result.canUndo.value).toBe(true);

        result.undo();
        expect(texts(result.blocks.value)).toEqual(['Hello']);
        expect(result.canRedo.value).toBe(true);

        result.redo();
        expect(texts(result.blocks.value)).toEqual(['Hello', 'Second']);
        expect(result.canRedo.value).toBe(false);
    });

    it('új módosítás törli az újra-vermet', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'A'));
        result.undo();
        result.setBlocks(withText(result.blocks.value, 'B'));

        expect(result.canRedo.value).toBe(false);
        expect(texts(result.blocks.value)).toEqual(['Hello', 'B']);
    });

    it('az azonos kulcsú, egymás utáni gépelés EGY lépésbe olvad', () => {
        const { result } = withSetup(() => useEditor(template()));
        const base = result.blocks.value;

        result.setBlocks(withText(base, 'H'), 'text:0.0.1');
        result.setBlocks(withText(base, 'He'), 'text:0.0.1');
        result.setBlocks(withText(base, 'Hey'), 'text:0.0.1');

        result.undo();
        expect(texts(result.blocks.value)).toEqual(['Hello']);
        expect(result.canUndo.value).toBe(false);
    });

    it('más kulcs (más blokk) új lépést nyit', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'X'), 'text:0.0.1');
        result.setBlocks(withText(result.blocks.value, 'Y'), 'text:0.0.2');

        result.undo();
        expect(texts(result.blocks.value)).toEqual(['Hello', 'X']);
    });

    it('a stílus-, tárgy- és preheader-változás is visszavonható (a pillanatkép mindet viszi)', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setStyles({ font_size: 16 });
        result.commit(() => {
            result.subject.value = 'New subject';
        });

        result.undo();
        expect(result.subject.value).toBe('Hi');
        expect(result.styles.value).toEqual({ font_size: 16 });

        result.undo();
        expect(result.styles.value).toEqual({});
    });

    it('a történet felső korlátja 100 lépés', () => {
        const { result } = withSetup(() => useEditor(template()));

        for (let i = 0; i < 105; i++) {
            result.setBlocks(withText(result.blocks.value, `n${i}`));
        }

        let steps = 0;
        while (result.canUndo.value) {
            result.undo();
            steps++;
        }

        expect(steps).toBe(100);
        // A legrégebbi 5 lépés kiesett: a legkorábbi elérhető állapotban már 5 új blokk van.
        expect(texts(result.blocks.value)).toHaveLength(6);
    });

    it('a szerverről jött állapot (replace) a történetet és a kijelölést is újraindítja', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'A'));
        result.selection.value = { kind: 'section', s: 0 };
        result.replace(template({ name: 'Restored', blocks: [] }));

        expect(result.name.value).toBe('Restored');
        expect(result.blocks.value).toEqual([]);
        expect(result.selection.value).toBeNull();
        expect(result.canUndo.value).toBe(false);
        expect(result.saveState.value).toBe('saved');
    });
});

describe('automatikus mentés', () => {
    it('a módosítás piszkos, és 1,5 s csend után EGYSZER ment', async () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'A'));
        expect(result.saveState.value).toBe('dirty');

        await vi.advanceTimersByTimeAsync(1000);
        result.setBlocks(withText(result.blocks.value, 'B'));
        await vi.advanceTimersByTimeAsync(1000);
        expect(put).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(600);
        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/templates/tpl-1/autosave');
        expect(put.mock.calls[0][1].manual).toBe(false);
        expect(result.saveState.value).toBe('saved');
        expect(result.version.value).toBe(3);
        expect(result.savedAt.value).toBe('2026-10-02T10:00:00Z');
    });

    it('a név változása is piszkossá teszi', async () => {
        const { result } = withSetup(() => useEditor(template()));

        result.name.value = 'Renamed';
        await nextTick();

        expect(result.saveState.value).toBe('dirty');
    });

    it('a szerver validációs hibája a hibaállapotba visz, a mezőhibák összefűzve', async () => {
        put.mockRejectedValueOnce({ message: 'Request failed', response: { data: { errors: { blocks: ['Kötelező a lábléc.'], subject: ['Túl hosszú.'] } } } });
        const { result } = withSetup(() => useEditor(template()));

        const ok = await result.save(true);

        expect(ok).toBe(false);
        expect(result.saveState.value).toBe('error');
        expect(result.saveError.value).toBe('Kötelező a lábléc. Túl hosszú.');
    });

    it('mentés közben jött változás után még egy kör fut (párhuzamos mentés nincs)', async () => {
        let release!: (value: unknown) => void;
        put.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
        const { result } = withSetup(() => useEditor(template()));

        const first = result.save(true);
        expect(result.saveState.value).toBe('saving');

        const second = result.save(true);
        expect(put).toHaveBeenCalledTimes(1);

        release({ data: { savedAt: null, version: 1 } });
        await Promise.all([first, second]);

        expect(put.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(result.saveState.value).toBe('saved');
    });

    it('piszkos állapotban az oldal elhagyása figyelmeztet, mentett állapotban nem', () => {
        const { result } = withSetup(() => useEditor(template()));
        const clean = new Event('beforeunload', { cancelable: true });

        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);

        result.setBlocks(withText(result.blocks.value, 'A'));
        const dirty = new Event('beforeunload', { cancelable: true });

        window.dispatchEvent(dirty);
        expect(dirty.defaultPrevented).toBe(true);
    });

    it('lecsatoláskor a függő mentés és a figyelmeztetés is megszűnik', async () => {
        const { result, unmount } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'A'));
        unmount();

        await vi.advanceTimersByTimeAsync(5000);
        expect(put).not.toHaveBeenCalled();

        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });
});

describe('szerializálás (a szerver felé menő payload)', () => {
    it('a blokk-JSON másolat, az üres preheader / stílus null, a név trimelve', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.name.value = '  Spaced  ';
        const payload = result.payload(true);

        expect(payload).toEqual({
            name: 'Spaced',
            subject: 'Hi',
            preheader: null,
            blocks: result.blocks.value,
            styles: null,
            manual: true,
        });
        expect(payload.blocks).not.toBe(result.blocks.value);
    });

    it('üres név helyett az eredeti név megy; a beállított stílus és preheader átmegy', () => {
        const { result } = withSetup(() => useEditor(template()));

        result.name.value = '   ';
        result.preheader.value = 'Preview text';
        result.setStyles({ font_size: 18 });

        expect(result.payload(false)).toMatchObject({ name: 'Welcome', preheader: 'Preview text', styles: { font_size: 18 }, manual: false });
    });

    it('a ténylegesen elküldött payload a mentéskori állapot', async () => {
        const { result } = withSetup(() => useEditor(template()));

        result.setBlocks(withText(result.blocks.value, 'Saved'));
        await result.save(true);

        expect(texts(put.mock.calls[0][1].blocks)).toEqual(['Hello', 'Saved']);
    });
});

describe('csak olvasható mód', () => {
    it('nincs autosave, nincs piszkos állapot, a mentés false, nincs elhagyás-figyelmeztetés', async () => {
        const { result } = withSetup(() => useEditor(template(), { readOnly: true }));

        expect(result.readOnly).toBe(true);

        result.setBlocks(withText(result.blocks.value, 'A'));
        expect(result.saveState.value).toBe('saved');

        await vi.advanceTimersByTimeAsync(5000);
        expect(put).not.toHaveBeenCalled();

        expect(await result.save(true)).toBe(false);
        expect(put).not.toHaveBeenCalled();

        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });

    it('a helyi szerkesztés (és visszavonás) működik — a munka másolatba menthető', () => {
        const { result } = withSetup(() => useEditor(template(), { readOnly: true }));

        result.setBlocks(withText(result.blocks.value, 'Draft'));
        expect(texts(result.payload(true).blocks as Block[])).toEqual(['Hello', 'Draft']);

        result.undo();
        expect(texts(result.blocks.value)).toEqual(['Hello']);
    });
});
