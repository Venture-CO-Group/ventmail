import axios, { AxiosError } from 'axios';
import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { clone, normalize } from './tree';
import type { Block, SaveState, Selection, TemplateData, TemplateStyles } from './types';

/*
 | A SZERKESZTŐ ÁLLAPOTA — egy helyen: a blokk-JSON (az egyetlen igazság), a globális
 | stílus, a levél-mezők, a kijelölés, a visszavonás/újra és az automatikus mentés.
 |
 | ## Visszavonás
 |
 | Pillanatkép-lista (a fa-műveletek új fát adnak, lásd `tree.ts`), felső korláttal. A
 | gépelés nem minden billentyűleütésnél ír pillanatképet: az egymás utáni szöveg-
 | módosítások egy lépésbe olvadnak (`coalesce`).
 |
 | ## Automatikus mentés
 |
 | Debounce-olt `PUT /templates/{uuid}/autosave`. A szerver FORDÍT és VALIDÁL (séma +
 | kötelező lábléc): a hibát a felső sáv mutatja, és a következő változás újra próbálja.
 | Párhuzamos mentés nincs — ha mentés közben jön változás, utána még egy kör fut.
 */

const HISTORY_LIMIT = 100;
const AUTOSAVE_DELAY = 1500;

interface Snapshot {
    blocks: Block[];
    styles: TemplateStyles;
    subject: string;
    preheader: string;
}

/**
 * `readOnly` (review R4-S3): a sablon aktív kampányt / journey-t szolgál, és a felhasználónak nincs
 * publikálási joga — a szerver úgyis elutasítaná a mentést. Ilyenkor nincs autosave és nincs
 * mentés (nincs 403-hurok, nincs „mentetlen változás" figyelmeztetés); a munka másolatba menthető.
 */
export function useEditor(template: TemplateData, options: { readOnly?: boolean } = {}) {
    const readOnly = options.readOnly === true;
    const name = ref(template.name);
    const subject = ref(template.subject ?? '');
    const preheader = ref(template.preheader ?? '');
    const blocks = ref<Block[]>(normalize(template.blocks)) as Ref<Block[]>;
    const styles = ref<TemplateStyles>(template.styles && !Array.isArray(template.styles) ? { ...template.styles } : {});
    const selection = ref<Selection>(null);

    const past = ref<Snapshot[]>([]);
    const future = ref<Snapshot[]>([]);
    let lastCoalesceKey: string | null = null;

    const saveState = ref<SaveState>('saved');
    const saveError = ref<string | null>(null);
    const savedAt = ref<string | null>(template.updatedAt);
    const version = ref<number | null>(null);
    let timer: number | undefined;
    let inFlight: Promise<void> | null = null;
    let pending = false;
    let suppressWatch = false;

    function snapshot(): Snapshot {
        return clone({ blocks: blocks.value, styles: styles.value, subject: subject.value, preheader: preheader.value });
    }

    function restore(state: Snapshot): void {
        suppressWatch = true;
        blocks.value = clone(state.blocks);
        styles.value = clone(state.styles);
        subject.value = state.subject;
        preheader.value = state.preheader;
        suppressWatch = false;
        markDirty();
    }

    /**
     * Egy módosítás a visszavonható történetbe. A `coalesceKey` (pl. `text:0.0.1`) az
     * egymás utáni, azonos helyű gépelést egyetlen lépéssé vonja össze.
     */
    function commit(mutate: () => void, coalesceKey: string | null = null): void {
        if (coalesceKey === null || coalesceKey !== lastCoalesceKey) {
            past.value.push(snapshot());

            if (past.value.length > HISTORY_LIMIT) past.value.shift();
        }

        lastCoalesceKey = coalesceKey;
        future.value = [];
        mutate();
        markDirty();
    }

    function setBlocks(next: Block[], coalesceKey: string | null = null): void {
        commit(() => {
            blocks.value = next;
        }, coalesceKey);
    }

    function setStyles(patch: Partial<TemplateStyles>): void {
        commit(() => {
            styles.value = { ...styles.value, ...patch };
        }, `styles:${Object.keys(patch).join(',')}`);
    }

    function undo(): void {
        const previous = past.value.pop();

        if (!previous) return;

        future.value.push(snapshot());
        lastCoalesceKey = null;
        restore(previous);
    }

    function redo(): void {
        const next = future.value.pop();

        if (!next) return;

        past.value.push(snapshot());
        lastCoalesceKey = null;
        restore(next);
    }

    /** A szerverről jött állapot (visszaállítás után) — a történet újraindul. */
    function replace(data: TemplateData): void {
        suppressWatch = true;
        name.value = data.name;
        subject.value = data.subject ?? '';
        preheader.value = data.preheader ?? '';
        blocks.value = normalize(data.blocks);
        styles.value = data.styles && !Array.isArray(data.styles) ? { ...data.styles } : {};
        selection.value = null;
        past.value = [];
        future.value = [];
        lastCoalesceKey = null;
        savedAt.value = data.updatedAt;
        saveState.value = 'saved';
        saveError.value = null;
        suppressWatch = false;
    }

    function markDirty(): void {
        if (suppressWatch || readOnly) return;

        saveState.value = 'dirty';
        window.clearTimeout(timer);
        timer = window.setTimeout(() => void save(false), AUTOSAVE_DELAY);
    }

    function payload(manual: boolean): Record<string, unknown> {
        return {
            name: name.value.trim() || template.name,
            subject: subject.value,
            preheader: preheader.value || null,
            blocks: clone(blocks.value),
            styles: Object.keys(styles.value).length === 0 ? null : clone(styles.value),
            manual,
        };
    }

    async function save(manual: boolean): Promise<boolean> {
        window.clearTimeout(timer);

        if (readOnly) return false;

        if (inFlight) {
            pending = true;
            await inFlight;

            if (!manual) return saveState.value === 'saved';
        }

        saveState.value = 'saving';
        let ok = false;

        inFlight = axios
            .put(`/templates/${template.uuid}/autosave`, payload(manual))
            .then((response) => {
                ok = true;
                savedAt.value = response.data.savedAt ?? null;
                version.value = response.data.version ?? null;
                saveError.value = null;
                saveState.value = pending ? 'dirty' : 'saved';
            })
            .catch((error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
                const errors = error.response?.data?.errors;
                saveError.value = errors ? Object.values(errors).flat().join(' ') : (error.response?.data?.message ?? error.message);
                saveState.value = 'error';
            })
            .finally(() => {
                inFlight = null;
            });

        await inFlight;

        if (pending) {
            pending = false;
            return save(manual);
        }

        return ok;
    }

    watch([name], () => markDirty());

    const canUndo = computed(() => past.value.length > 0);
    const canRedo = computed(() => future.value.length > 0);

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (readOnly) return;

        if (saveState.value === 'dirty' || saveState.value === 'saving' || saveState.value === 'error') {
            event.preventDefault();
        }
    }

    window.addEventListener('beforeunload', onBeforeUnload);

    onBeforeUnmount(() => {
        window.clearTimeout(timer);
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    return {
        name,
        subject,
        preheader,
        blocks,
        styles,
        selection,
        saveState,
        saveError,
        savedAt,
        version,
        canUndo,
        canRedo,
        commit,
        setBlocks,
        setStyles,
        undo,
        redo,
        replace,
        save,
        payload,
        markDirty,
        readOnly,
    };
}

export type EditorApi = ReturnType<typeof useEditor>;
