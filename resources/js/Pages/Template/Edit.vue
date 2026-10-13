<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { VtButton, VtDrawer, VtModal } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import EdSegment from '../../Components/Editor/EdSegment.vue';
import EditorCanvas from '../../Components/Editor/EditorCanvas.vue';
import NameModal from '../../Components/Editor/NameModal.vue';
import OptimizePanel from '../../Components/Editor/OptimizePanel.vue';
import PalettePanel from '../../Components/Editor/PalettePanel.vue';
import PreviewModal from '../../Components/Editor/PreviewModal.vue';
import PropertiesPanel from '../../Components/Editor/PropertiesPanel.vue';
import StylesPanel from '../../Components/Editor/StylesPanel.vue';
import TestSendForm from '../../Components/Editor/TestSendForm.vue';
import VersionsDrawer from '../../Components/Editor/VersionsDrawer.vue';
import { dragging } from '../../Components/Editor/dragState';
import { resolveStyles } from '../../Components/Editor/render';
import {
    clone,
    contentBlock,
    duplicateBlock,
    duplicateSection,
    eachBlock,
    getBlock,
    insertBlock,
    insertSection,
    moveBlock,
    moveSection,
    nudgeBlock,
    parsePath,
    removeBlock,
    removeSection,
    section,
    sectionFromLayout,
    setAttrs,
    setSectionAttrs,
    setSectionLayout,
} from '../../Components/Editor/tree';
import type { AssetDef, Attrs, Block, BlockPath, DragPayload, DropTarget, EditorCatalog, LayoutDef, SavedBlockDef, TemplateData } from '../../Components/Editor/types';
import { useEditor } from '../../Components/Editor/useEditor';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import { useCompactNav } from '../../composables/useCompactNav';
import { useBelow } from '../../Components/Editor/useBelow';
import AppLayout from '../../Layouts/AppLayout.vue';
import '../../editor.css';

/*
 | DRAG-AND-DROP SABLONSZERKESZTŐ (v1.1 · 4. modul).
 |
 | Bal oldalon a sín (Hozzáadás · Stílusok · Optimalizálás) és a panel, középen a vászon,
 | jobbra a tulajdonságpanel; felül a név, a mentés-állapot, a visszavonás/újra, az
 | Előnézet és a Mentés és kilépés legördülővel (Mentés másként, Mentés sablonként, Teszt
 | küldése).
 |
 | ## Az igazság a blokk-JSON — a fordítás a SZERVEREN
 |
 | A vászon a fát rajzolja (közelítés); a mentés, az előnézet és az optimalizálás a szerver
 | MJML → HTML + plaintext fordítóján megy. A szerver validál (séma, kötelező lábléc) —
 | a felület csak megmutatja a hibát.
 */
const props = defineProps<{
    template: TemplateData;
    catalog: EditorCatalog;
    savedBlocks: SavedBlockDef[];
    assets: AssetDef[];
    testRecipients: Array<{ email: string; kind: string }>;
    senderDomains: string[];
    /** CSAK OLVASHATÓ MÓD oka (review R4-S3): aktív kampány / journey sablonja, publikálási jog nélkül. */
    writeDenial: string | null;
}>();

const { t } = useI18n();
const locked = computed(() => props.writeDenial !== null);
const editor = useEditor(props.template, { readOnly: props.writeDenial !== null });
const compact = useCompactNav();
// KÖZÉPSŐ szélesség (≈ 1280 alatt): a tulajdonságpanel fiókként nyílik, a vászon kapja a helyet.
const narrow = useBelow(10);
const propsAsSheet = computed(() => compact.value || narrow.value);

const panel = ref<'add' | 'styles' | 'optimize'>('add');
const device = ref<'desktop' | 'mobile' | 'dark'>('desktop');
const target = ref<DropTarget>(null);
const saved = ref<SavedBlockDef[]>([...props.savedBlocks]);
const assets = ref<AssetDef[]>([...props.assets]);
const menuOpen = ref(false);
const previewOpen = ref(false);
const versionsOpen = ref(false);
const testOpen = ref(false);
const panelSheet = ref(false);
const propsSheet = ref(false);
const notice = ref<string | null>(null);
const optimizer = ref<InstanceType<typeof OptimizePanel> | null>(null);

const nameModal = ref<{ mode: 'saveAs' | 'saveTemplate' | 'saveBlock'; payload?: { kind: 'section' | 'block'; block: Block } } | null>(null);
const nameError = ref<string | null>(null);
const nameBusy = ref(false);

const styles = computed(() => resolveStyles(props.catalog, editor.styles.value));

const selectedTarget = computed<Block | null>(() => {
    const selection = editor.selection.value;

    if (selection === null) return null;
    if (selection.kind === 'section') return editor.blocks.value[selection.s] ?? null;

    return getBlock(editor.blocks.value, selection) ?? null;
});

/*
 | Keskeny nézetben a kijelölés a tulajdonság-fiókot nyitja — kivéve a helyben szerkeszthető
 | szöveget (címsor, bekezdés): ott a fiók csak eltakarná, amit épp ír.
 */
watch(
    () => JSON.stringify(editor.selection.value),
    () => {
        const block = selectedTarget.value;

        if (propsAsSheet.value && editor.selection.value !== null && !['heading', 'text'].includes(block?.type ?? '')) {
            propsSheet.value = true;
        }
    },
);

/* ------------------------------------------------------------------ beszúrás */

/** A lábléc-szakasz ELÉ szúrunk, ha a levél végén lábléc áll — a kötelező elem maradjon alul. */
function appendIndex(): number {
    const blocks = editor.blocks.value;
    const last = blocks[blocks.length - 1];
    const lastHasFooter = (last?.children ?? []).some((column) => (column.children ?? []).some((block) => ['footer', 'unsubscribe'].includes(block.type)));

    return lastHasFooter ? blocks.length - 1 : blocks.length;
}

function resolvePayload(payload: DragPayload): { kind: 'section'; block: Block } | { kind: 'block'; block: Block } | null {
    switch (payload.source) {
        case 'palette': {
            const item = props.catalog.blocks.find((block) => block.id === payload.id);

            return item ? { kind: 'block', block: contentBlock(item.type, item.defaults) } : null;
        }
        case 'layout': {
            const layout = props.catalog.layouts.find((item) => item.key === payload.key);

            return layout ? { kind: 'section', block: sectionFromLayout(layout) } : null;
        }
        case 'pattern': {
            const pattern = props.catalog.patterns.find((item) => item.key === payload.key);

            return pattern ? { kind: 'section', block: clone(pattern.section) } : null;
        }
        case 'saved': {
            const item = saved.value.find((block) => block.uuid === payload.uuid);

            return item ? { kind: item.kind, block: clone(item.block) } : null;
        }
        default:
            return null;
    }
}

/** KATTINTÁSSAL / ENTERREL beszúrás (a húzás billentyűzetes alternatívája). */
function add(payload: DragPayload): void {
    const resolved = resolvePayload(payload);
    const selection = editor.selection.value;

    if (!resolved) return;

    if (resolved.kind === 'section') {
        const index = selection ? selection.s + 1 : appendIndex();
        editor.setBlocks(insertSection(editor.blocks.value, index, resolved.block));
        editor.selection.value = { kind: 'section', s: index };
    } else if (selection?.kind === 'block') {
        editor.setBlocks(insertBlock(editor.blocks.value, { s: selection.s, c: selection.c, index: selection.b + 1 }, resolved.block));
        editor.selection.value = { kind: 'block', s: selection.s, c: selection.c, b: selection.b + 1 };
    } else if (selection?.kind === 'section') {
        const length = editor.blocks.value[selection.s]?.children?.[0]?.children?.length ?? 0;
        editor.setBlocks(insertBlock(editor.blocks.value, { s: selection.s, c: 0, index: length }, resolved.block));
        editor.selection.value = { kind: 'block', s: selection.s, c: 0, b: length };
    } else {
        const index = appendIndex();
        const wrapper = section();
        wrapper.children![0].children = [resolved.block];
        editor.setBlocks(insertSection(editor.blocks.value, index, wrapper));
        editor.selection.value = { kind: 'block', s: index, c: 0, b: 0 };
    }

    panelSheet.value = false;
}

/** HÚZÁS vége: a cél a jelző helye, a forrás a `dragging` állapot. */
function drop(dropTarget: DropTarget): void {
    const payload = dragging.value;

    dragging.value = null;
    target.value = null;

    if (!payload || !dropTarget) return;

    if (payload.source === 'canvas-block') {
        if (dropTarget.kind === 'block') {
            const moved = moveBlock(editor.blocks.value, payload.path, dropTarget);
            editor.setBlocks(moved.blocks);
            editor.selection.value = { kind: 'block', ...moved.path };
        } else {
            const block = getBlock(editor.blocks.value, payload.path);

            if (!block) return;

            const wrapper = section();
            wrapper.children![0].children = [clone(block)];
            const inserted = insertSection(editor.blocks.value, dropTarget.index, wrapper);
            const shifted = payload.path.s >= dropTarget.index ? { ...payload.path, s: payload.path.s + 1 } : payload.path;
            editor.setBlocks(removeBlock(inserted, shifted));
            editor.selection.value = { kind: 'block', s: dropTarget.index, c: 0, b: 0 };
        }

        return;
    }

    if (payload.source === 'canvas-section') {
        if (dropTarget.kind !== 'section') return;

        const moved = moveSection(editor.blocks.value, payload.s, dropTarget.index);
        editor.setBlocks(moved.blocks);
        editor.selection.value = { kind: 'section', s: moved.index };

        return;
    }

    const resolved = resolvePayload(payload);

    if (!resolved) return;

    if (resolved.kind === 'block' && dropTarget.kind === 'block') {
        editor.setBlocks(insertBlock(editor.blocks.value, dropTarget, resolved.block));
        editor.selection.value = { kind: 'block', s: dropTarget.s, c: dropTarget.c, b: dropTarget.index };

        return;
    }

    const index = dropTarget.kind === 'section' ? dropTarget.index : dropTarget.s + 1;
    let sectionBlock = resolved.block;

    if (resolved.kind === 'block') {
        sectionBlock = section();
        sectionBlock.children![0].children = [resolved.block];
    }

    editor.setBlocks(insertSection(editor.blocks.value, index, sectionBlock));
    editor.selection.value = resolved.kind === 'block' ? { kind: 'block', s: index, c: 0, b: 0 } : { kind: 'section', s: index };
}

function onDragEnd(): void {
    dragging.value = null;
    target.value = null;
}

/* ------------------------------------------------------------------ műveletek */

function blockAction(path: BlockPath, action: 'up' | 'down' | 'duplicate' | 'delete' | 'save'): void {
    if (action === 'up' || action === 'down') {
        const moved = nudgeBlock(editor.blocks.value, path, action === 'up' ? -1 : 1);
        editor.setBlocks(moved.blocks);
        editor.selection.value = { kind: 'block', ...moved.path };
        void focusSelected();
    } else if (action === 'duplicate') {
        editor.setBlocks(duplicateBlock(editor.blocks.value, path));
        editor.selection.value = { kind: 'block', s: path.s, c: path.c, b: path.b + 1 };
    } else if (action === 'delete') {
        editor.setBlocks(removeBlock(editor.blocks.value, path));
        editor.selection.value = null;
    } else {
        const block = getBlock(editor.blocks.value, path);

        if (block) openName('saveBlock', { kind: 'block', block: clone(block) });
    }
}

function sectionAction(s: number, action: 'up' | 'down' | 'duplicate' | 'delete' | 'save'): void {
    if (action === 'up' || action === 'down') {
        const to = action === 'up' ? s - 1 : s + 2;

        if (to < 0 || to > editor.blocks.value.length) return;

        const moved = moveSection(editor.blocks.value, s, to);
        editor.setBlocks(moved.blocks);
        editor.selection.value = { kind: 'section', s: moved.index };
    } else if (action === 'duplicate') {
        editor.setBlocks(duplicateSection(editor.blocks.value, s));
        editor.selection.value = { kind: 'section', s: s + 1 };
    } else if (action === 'delete') {
        editor.setBlocks(removeSection(editor.blocks.value, s));
        editor.selection.value = null;
    } else {
        const block = editor.blocks.value[s];

        if (block) openName('saveBlock', { kind: 'section', block: clone(block) });
    }
}

function updateBlock(path: BlockPath, patch: Attrs, coalesceKey: string): void {
    editor.setBlocks(setAttrs(editor.blocks.value, path, patch), coalesceKey);
}

function updateSelected(patch: Attrs, coalesceKey: string): void {
    const selection = editor.selection.value;

    if (selection === null) return;

    if (selection.kind === 'section') {
        editor.setBlocks(setSectionAttrs(editor.blocks.value, selection.s, patch), coalesceKey);
    } else {
        updateBlock(selection, patch, coalesceKey);
    }
}

function setLayout(layout: LayoutDef): void {
    const selection = editor.selection.value;

    if (selection?.kind === 'section') {
        editor.setBlocks(setSectionLayout(editor.blocks.value, selection.s, layout));
    }
}

async function focusSelected(): Promise<void> {
    await nextTick();
    const selection = editor.selection.value;

    if (selection?.kind === 'block') {
        document.querySelector<HTMLElement>(`[data-testid="block-${selection.s}-${selection.c}-${selection.b}"]`)?.focus();
    }
}

function focusPath(value: string): void {
    const path = parsePath(value);

    if (!path) return;

    editor.selection.value = { kind: 'block', ...path };
    void nextTick(() => document.querySelector(`[data-testid="block-${path.s}-${path.c}-${path.b}"]`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
}

/** UTM-paraméterek minden http(s) linkre, amelyikről hiányoznak. */
function addUtm(): void {
    const campaign = (editor.name.value || 'ventmail').toLowerCase().normalize('NFD').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'ventmail';
    const tag = (url: string): string => {
        if (!/^https:\/\//i.test(url) || /[?&]utm_source=/.test(url)) return url;

        const [base, hash] = url.split('#');
        const glue = base.includes('?') ? '&' : '?';

        return `${base}${glue}utm_source=ventmail&utm_medium=email&utm_campaign=${campaign}${hash !== undefined ? `#${hash}` : ''}`;
    };

    let next = clone(editor.blocks.value);

    eachBlock(next, (block, path) => {
        const attrs = { ...(block.attrs ?? {}) };

        for (const field of ['href', 'share_url', 'base_url']) {
            if (typeof attrs[field] === 'string') attrs[field] = tag(attrs[field] as string);
        }

        for (const field of ['content', 'description']) {
            if (typeof attrs[field] === 'string') {
                attrs[field] = (attrs[field] as string).replace(/href="([^"]*)"/g, (_, href: string) => `href="${tag(href)}"`);
            }
        }

        next = setAttrs(next, path, attrs);
    });

    editor.setBlocks(next);
    void nextTick(() => optimizer.value?.run());
}

/* ------------------------------------------------------------------ mentés */

async function saveAndExit(): Promise<void> {
    menuOpen.value = false;

    if (await editor.save(true)) {
        router.visit('/templates');
    }
}

function openName(mode: 'saveAs' | 'saveTemplate' | 'saveBlock', payload?: { kind: 'section' | 'block'; block: Block }): void {
    menuOpen.value = false;
    nameError.value = null;
    nameModal.value = { mode, payload };
}

const nameInitial = computed(() => {
    const mode = nameModal.value?.mode;

    if (mode === 'saveBlock') return t('editor.saveBlock.defaultName');

    return t('editor.save.copyName', { name: editor.name.value });
});

async function submitName(name: string): Promise<void> {
    const modal = nameModal.value;

    if (!modal) return;

    nameBusy.value = true;
    nameError.value = null;

    try {
        if (modal.mode === 'saveBlock' && modal.payload) {
            const response = await axios.post<SavedBlockDef>('/template-blocks', { name, kind: modal.payload.kind, block: modal.payload.block });
            saved.value = [...saved.value, response.data].sort((a, b) => a.name.localeCompare(b.name));
            notice.value = t('editor.saveBlock.done', { name });
        } else {
            const response = await axios.post<{ uuid: string; url: string }>(`/templates/${props.template.uuid}/copy`, { ...editor.payload(true), name });

            if (modal.mode === 'saveAs') {
                // A zárolt sablon nem menthető (a szerver elutasítaná) — a másolat viszi a munkát.
                if (!locked.value) await editor.save(true);
                router.visit(response.data.url);
            } else {
                notice.value = t('editor.save.templateDone', { name });
            }
        }

        nameModal.value = null;
    } catch (caught) {
        const failure = caught as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
        nameError.value = Object.values(failure.response?.data?.errors ?? {}).flat()[0] ?? failure.response?.data?.message ?? failure.message;
    } finally {
        nameBusy.value = false;
    }
}

function restored(data: TemplateData): void {
    editor.replace(data);
    notice.value = t('editor.versions.restored');
}

/* ------------------------------------------------------------------ billentyűzet */

function editingText(): boolean {
    const active = document.activeElement as HTMLElement | null;

    return !!active && (active.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName));
}

function onKey(event: KeyboardEvent): void {
    const mod = event.metaKey || event.ctrlKey;

    if (mod && event.key.toLowerCase() === 'z' && !editingText()) {
        event.preventDefault();
        event.shiftKey ? editor.redo() : editor.undo();
    } else if (mod && event.key.toLowerCase() === 'y' && !editingText()) {
        event.preventDefault();
        editor.redo();
    } else if (mod && event.key.toLowerCase() === 's') {
        event.preventDefault();
        void editor.save(true);
    } else if (event.key === 'Escape' && !editingText()) {
        editor.selection.value = null;
    } else if ((event.key === 'Delete' || event.key === 'Backspace') && !editingText()) {
        const selection = editor.selection.value;

        if (selection?.kind === 'block') {
            event.preventDefault();
            blockAction(selection, 'delete');
        }
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const statusLabel = computed(() => {
    switch (editor.saveState.value) {
        case 'saving':
            return t('editor.status.saving');
        case 'dirty':
            return t('editor.status.dirty');
        case 'error':
            return t('editor.status.error');
        default:
            return t('editor.status.saved');
    }
});

const deviceOptions = computed(() => [
    { value: 'desktop', label: t('editor.device.desktop'), icon: 'desktop' },
    { value: 'mobile', label: t('editor.device.mobile'), icon: 'mobile' },
    { value: 'dark', label: t('editor.device.dark'), icon: 'moon' },
]);

const railItems = computed(() => [
    { id: 'add' as const, icon: 'plus', label: t('editor.rail.add') },
    { id: 'styles' as const, icon: 'palette', label: t('editor.rail.styles') },
    { id: 'optimize' as const, icon: 'gauge', label: t('editor.rail.optimize') },
]);

function pickPanel(id: 'add' | 'styles' | 'optimize'): void {
    panel.value = id;

    if (compact.value) panelSheet.value = true;
}

const beforeSend = (): Promise<boolean> => editor.save(true);

function openTest(): void {
    menuOpen.value = false;
    testOpen.value = true;
}
</script>

<template>
    <Head :title="editor.name.value" />

    <AppLayout editor-mode>
        <div class="vm-editor" data-testid="editor">
            <!-- FELSŐ SÁV -->
            <header class="vm-ed-top">
                <div class="vm-ed-top__name">
                    <input v-model="editor.name.value" :aria-label="t('editor.top.name')" maxlength="120" :readonly="locked" data-testid="editor-name" />
                    <span class="vm-ed-status" :class="`vm-ed-status--${editor.saveState.value}`" role="status" data-testid="save-status" :data-state="editor.saveState.value">
                        <span class="vm-ed-status__dot" aria-hidden="true" />{{ statusLabel }}
                    </span>
                    <button type="button" class="vm-ed-iconbtn" :disabled="locked || !editor.canUndo.value" :aria-label="t('editor.top.undo')" :title="t('editor.top.undo')" data-testid="editor-undo" @click="editor.undo()">
                        <VmIcon name="undo" />
                    </button>
                    <button type="button" class="vm-ed-iconbtn" :disabled="locked || !editor.canRedo.value" :aria-label="t('editor.top.redo')" :title="t('editor.top.redo')" data-testid="editor-redo" @click="editor.redo()">
                        <VmIcon name="redo" />
                    </button>
                    <button type="button" class="vm-ed-iconbtn" :aria-label="t('editor.top.versions')" :title="t('editor.top.versions')" data-testid="editor-versions" @click="versionsOpen = true">
                        <VmIcon name="history" />
                    </button>
                </div>

                <div class="vm-ed-top__actions">
                    <VtButton variant="ghost" data-testid="editor-preview" @click="previewOpen = true"><VmIcon name="eye" /> {{ t('editor.top.preview') }}</VtButton>
                    <div class="vm-ed-split">
                        <VtButton v-if="locked" data-testid="editor-copy-locked" @click="openName('saveAs')">{{ t('editor.locked.copy') }}</VtButton>
                        <VtButton v-else :loading="editor.saveState.value === 'saving'" data-testid="editor-save-exit" @click="saveAndExit">{{ t('editor.top.saveExit') }}</VtButton>
                        <button
                            type="button"
                            class="vm-ed-iconbtn"
                            aria-haspopup="menu"
                            :aria-expanded="menuOpen"
                            :aria-label="t('editor.top.more')"
                            data-testid="editor-save-menu"
                            @click="menuOpen = !menuOpen"
                        >
                            <VmIcon name="chevron" />
                        </button>
                        <div v-if="menuOpen" class="vm-ed-split__menu" role="menu" @keydown.esc="menuOpen = false">
                            <button type="button" role="menuitem" class="vm-ed-menuitem" data-testid="menu-save-as" @click="openName('saveAs')">{{ t('editor.top.saveAs') }}</button>
                            <button type="button" role="menuitem" class="vm-ed-menuitem" data-testid="menu-save-template" @click="openName('saveTemplate')">{{ t('editor.top.saveTemplate') }}</button>
                            <button type="button" role="menuitem" class="vm-ed-menuitem" data-testid="menu-test-send" @click="openTest">{{ t('editor.top.sendTest') }}</button>
                        </div>
                    </div>
                </div>

                <div v-if="locked" class="vm-banner vm-banner--warning vm-ed-locked" role="status" data-testid="template-locked">
                    <p><strong>{{ t('editor.locked.title') }}</strong> {{ writeDenial }}</p>
                    <VtButton variant="ghost" data-testid="editor-copy-banner" @click="openName('saveAs')">{{ t('editor.locked.copy') }}</VtButton>
                </div>
                <p v-if="editor.saveState.value === 'error' && editor.saveError.value" class="vm-banner vm-banner--danger vm-ed-error" role="alert" data-testid="save-error">
                    {{ editor.saveError.value }}
                </p>
                <p v-if="notice" class="vm-banner vm-ed-error" role="status" data-testid="editor-notice" @click="notice = null">{{ notice }}</p>
            </header>

            <!-- CSAK OLVASHATÓ: az `inert` a vásznat, a sínt és a paneleket egyszerre teszi kezelhetetlenné. -->
            <div class="vm-ed-body" :class="{ 'vm-ed-body--compact': compact, 'vm-ed-body--narrow': narrow && !compact, 'vm-ed-body--locked': locked }" :inert="locked" data-testid="editor-body">
                <!-- SÍN -->
                <nav class="vm-ed-rail" :aria-label="t('editor.rail.label')">
                    <button
                        v-for="item in railItems"
                        :key="item.id"
                        type="button"
                        class="vm-ed-rail__btn"
                        :aria-pressed="panel === item.id"
                        :data-testid="`rail-${item.id}`"
                        @click="pickPanel(item.id)"
                    >
                        <VmIcon :name="item.icon" />{{ item.label }}
                    </button>
                    <button v-if="propsAsSheet" type="button" class="vm-ed-rail__btn" data-testid="rail-properties" @click="propsSheet = true">
                        <VmIcon name="sliders" />{{ t('editor.rail.properties') }}
                    </button>
                </nav>

                <!-- BAL PANEL -->
                <aside v-if="!compact" class="vm-ed-panel" :aria-label="railItems.find((item) => item.id === panel)?.label">
                    <PalettePanel v-if="panel === 'add'" :catalog="catalog" :saved-blocks="saved" @add="add" @dragend="onDragEnd" />
                    <StylesPanel v-else-if="panel === 'styles'" :styles="styles" :catalog="catalog" @update="editor.setStyles($event)" />
                    <OptimizePanel
                        v-else
                        ref="optimizer"
                        :uuid="template.uuid"
                        :device="device"
                        :payload="() => editor.payload(false)"
                        :recipients="testRecipients"
                        :domains="senderDomains"
                        :before-send="beforeSend"
                        @device="device = $event"
                        @focus="focusPath"
                        @add-utm="addUtm"
                    />
                </aside>

                <!-- VÁSZON -->
                <main class="vm-ed-stage" @dragend="onDragEnd">
                    <EdSegment v-model="device" :options="deviceOptions" :label="t('editor.device.label')" testid="canvas-device" />
                    <EditorCanvas
                        :blocks="editor.blocks.value"
                        :selection="editor.selection.value"
                        :styles="styles"
                        :catalog="catalog"
                        :saved-blocks="saved"
                        :device="device"
                        :target="target"
                        @select="editor.selection.value = $event"
                        @update="updateBlock"
                        @block-action="blockAction"
                        @section-action="sectionAction"
                        @target="target = $event"
                        @drop="drop"
                    />
                </main>

                <!-- TULAJDONSÁGOK -->
                <aside v-if="!propsAsSheet" class="vm-ed-panel vm-ed-props" :aria-label="t('editor.props.label')">
                    <PropertiesPanel
                        :selection="editor.selection.value"
                        :target="selectedTarget"
                        :catalog="catalog"
                        :assets="assets"
                        :subject="editor.subject.value"
                        :preheader="editor.preheader.value"
                        :marketing="template.marketing"
                        @update="updateSelected"
                        @layout="setLayout"
                        @subject="editor.commit(() => (editor.subject.value = $event), 'subject')"
                        @preheader="editor.commit(() => (editor.preheader.value = $event), 'preheader')"
                        @uploaded="assets = [$event, ...assets]"
                    />
                </aside>
            </div>
        </div>

        <!-- KOMPAKT NÉZET: a panelek alsó lapként -->
        <VtDrawer v-if="compact" :open="panelSheet" side="bottom" :aria-label="railItems.find((item) => item.id === panel)?.label" :close-label="t('editor.common.close')" @update:open="panelSheet = $event">
            <PalettePanel v-if="panel === 'add'" :catalog="catalog" :saved-blocks="saved" @add="add" @dragend="onDragEnd" />
            <StylesPanel v-else-if="panel === 'styles'" :styles="styles" :catalog="catalog" @update="editor.setStyles($event)" />
            <OptimizePanel
                v-else
                :uuid="template.uuid"
                :device="device"
                :payload="() => editor.payload(false)"
                :recipients="testRecipients"
                :domains="senderDomains"
                :before-send="beforeSend"
                @device="device = $event"
                @focus="focusPath"
                @add-utm="addUtm"
            />
        </VtDrawer>
        <VtDrawer v-if="propsAsSheet" :open="propsSheet" :side="compact ? 'bottom' : 'right'" :aria-label="t('editor.props.label')" :close-label="t('editor.common.close')" @update:open="propsSheet = $event">
            <PropertiesPanel
                :selection="editor.selection.value"
                :target="selectedTarget"
                :catalog="catalog"
                :assets="assets"
                :subject="editor.subject.value"
                :preheader="editor.preheader.value"
                :marketing="template.marketing"
                @update="updateSelected"
                @layout="setLayout"
                @subject="editor.commit(() => (editor.subject.value = $event), 'subject')"
                @preheader="editor.commit(() => (editor.preheader.value = $event), 'preheader')"
                @uploaded="assets = [$event, ...assets]"
            />
        </VtDrawer>

        <PreviewModal v-model:open="previewOpen" :uuid="template.uuid" :payload="() => editor.payload(false)" />
        <VersionsDrawer v-model:open="versionsOpen" :uuid="template.uuid" :before-restore="() => editor.save(true)" @restored="restored" />

        <VtModal :open="testOpen" :title="t('editor.testSend.title')" @update:open="testOpen = $event" @close="testOpen = false">
            <TestSendForm :uuid="template.uuid" :recipients="testRecipients" :domains="senderDomains" :before-send="beforeSend" />
        </VtModal>

        <NameModal
            :open="nameModal !== null"
            :title="nameModal ? t(`editor.nameModal.${nameModal.mode}`) : ''"
            :initial="nameInitial"
            :submit-label="t('editor.common.save')"
            :error="nameError"
            :busy="nameBusy"
            @update:open="!$event && (nameModal = null)"
            @submit="submitName"
        />
    </AppLayout>
</template>
