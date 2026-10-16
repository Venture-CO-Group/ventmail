<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import CanvasBlock from './CanvasBlock.vue';
import { dragging, isSectionPayload } from './dragState';
import { padding, type Resolved } from './render';
import type { Attrs, Block, BlockPath, DropTarget, EditorCatalog, SavedBlockDef, Selection } from './types';
import { DRAG_MIME } from './types';

/*
 | A VÁSZON: szakaszok → oszlopok → blokkok, beszúrási jelzőkkel.
 |
 | ## Húzás (D3)
 |
 | Natív HTML5 DnD. A `dragover` a mutató helyzetéből számolja a célt: blokk fölött a
 | blokk felső / alsó fele dönt (elé / mögé), üres oszlopban az oszlop eleje, szakasz-
 | húzásnál a szakasz felső / alsó fele. A jelző (`vm-ed-drop--active`) pontosan ott
 | világít, ahova az elem kerül. Érintőképernyőn a natív DnD nem működik — ott a
 | paletta-elemre KATTINTÁS illeszt be (a kijelölés után, vagy a végére).
 */
const props = defineProps<{
    blocks: Block[];
    selection: Selection;
    styles: Resolved;
    catalog: EditorCatalog;
    savedBlocks: SavedBlockDef[];
    device: 'desktop' | 'mobile' | 'dark';
    target: DropTarget;
}>();

const emit = defineEmits<{
    select: [selection: Selection];
    update: [path: BlockPath, patch: Attrs, coalesceKey: string];
    blockAction: [path: BlockPath, action: 'up' | 'down' | 'duplicate' | 'delete' | 'save'];
    sectionAction: [s: number, action: 'up' | 'down' | 'duplicate' | 'delete' | 'save'];
    target: [target: DropTarget];
    drop: [target: DropTarget];
}>();

const { t } = useI18n();

const fontFamily = computed(() => props.catalog.fontStacks[props.styles.font] ?? props.catalog.fontStacks.sans);

function sectionPayload(): boolean {
    if (dragging.value?.source === 'saved') {
        return props.savedBlocks.find((block) => block.uuid === (dragging.value as { uuid: string }).uuid)?.kind === 'section';
    }

    return isSectionPayload(dragging.value);
}

function set(target: DropTarget, event: DragEvent): void {
    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = dragging.value?.source?.startsWith('canvas') ? 'move' : 'copy';
    }

    if (JSON.stringify(target) !== JSON.stringify(props.target)) {
        emit('target', target);
    }
}

function half(event: DragEvent): 'before' | 'after' {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();

    return event.clientY < rect.top + rect.height / 2 ? 'before' : 'after';
}

function overBlock(event: DragEvent, s: number, c: number, b: number): void {
    if (!dragging.value) return;

    event.stopPropagation();

    if (sectionPayload()) {
        set({ kind: 'section', index: s + (half(event) === 'before' ? 0 : 1) }, event);
        return;
    }

    set({ kind: 'block', s, c, index: b + (half(event) === 'before' ? 0 : 1) }, event);
}

function overColumn(event: DragEvent, s: number, c: number, length: number): void {
    if (!dragging.value || sectionPayload()) return;

    event.stopPropagation();
    set({ kind: 'block', s, c, index: length }, event);
}

function overSection(event: DragEvent, s: number): void {
    if (!dragging.value) return;

    event.stopPropagation();
    set({ kind: 'section', index: s + (half(event) === 'before' ? 0 : 1) }, event);
}

function overEnd(event: DragEvent): void {
    if (!dragging.value) return;

    set({ kind: 'section', index: props.blocks.length }, event);
}

function onDrop(event: DragEvent): void {
    event.preventDefault();
    emit('drop', props.target);
}

function isBlockTarget(s: number, c: number, index: number): boolean {
    const target = props.target;

    return target !== null && target.kind === 'block' && target.s === s && target.c === c && target.index === index;
}

function isSectionTarget(index: number): boolean {
    return props.target !== null && props.target.kind === 'section' && props.target.index === index;
}

function startBlockDrag(event: DragEvent, path: BlockPath): void {
    dragging.value = { source: 'canvas-block', path };
    event.dataTransfer?.setData(DRAG_MIME, JSON.stringify(dragging.value));

    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function startSectionDrag(event: DragEvent, s: number): void {
    dragging.value = { source: 'canvas-section', s };
    event.dataTransfer?.setData(DRAG_MIME, JSON.stringify(dragging.value));

    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function isSelectedBlock(s: number, c: number, b: number): boolean {
    const selection = props.selection;

    return selection !== null && selection.kind === 'block' && selection.s === s && selection.c === c && selection.b === b;
}

function columnFlex(column: Block): string {
    const width = parseFloat(String(column.attrs?.width ?? '100'));

    return `${Number.isFinite(width) ? width : 100} 1 0`;
}

function sectionStyle(section: Block): Record<string, string | undefined> {
    const attrs = section.attrs ?? {};

    return {
        background: String(attrs.background ?? props.styles.content_background),
        padding: padding(attrs.padding) ?? 'var(--vt-space-4) var(--vt-space-6)',
    };
}
</script>

<template>
    <div
        class="vm-ed-canvas"
        :class="{ 'vm-ed-canvas--mobile': device === 'mobile', 'vm-ed-canvas--dark': device === 'dark' }"
        :style="{ maxInlineSize: device === 'mobile' ? undefined : `${styles.width}px`, background: styles.background, fontFamily }"
        data-testid="editor-canvas"
        :data-width="styles.width"
        @dragover="overEnd"
        @drop="onDrop"
        @click="emit('select', null)"
    >
        <template v-for="(section, s) in blocks" :key="s">
            <div class="vm-ed-drop vm-ed-drop--section" :class="{ 'vm-ed-drop--active': isSectionTarget(s) }" :data-testid="`drop-section-${s}`" />

            <section
                class="vm-ed-section"
                :class="{ 'vm-ed-section--selected': selection?.kind === 'section' && selection.s === s,
                    'vm-ed-hide-mobile': section.attrs?.hide_on_mobile === true,
                }"
                :style="sectionStyle(section)"
                :data-testid="`section-${s}`"
                @click.stop="emit('select', { kind: 'section', s })"
                @dragover="overSection($event, s)"
            >
                <div class="vm-ed-section__bar" role="toolbar" :aria-label="t('editor.sectionTools.label')">
                    <span class="vm-ed-iconbtn vm-ed-grip" draggable="true" role="button" :aria-label="t('editor.blockTools.drag')" :title="t('editor.blockTools.drag')" @dragstart="startSectionDrag($event, s)">
                        <VmIcon name="grip" />
                    </span>
                    <button type="button" class="vm-ed-iconbtn" :disabled="s === 0" :aria-label="t('editor.blockTools.up')" :title="t('editor.blockTools.up')" @click.stop="emit('sectionAction', s, 'up')">
                        <VmIcon name="arrow-up" />
                    </button>
                    <button type="button" class="vm-ed-iconbtn" :disabled="s === blocks.length - 1" :aria-label="t('editor.blockTools.down')" :title="t('editor.blockTools.down')" @click.stop="emit('sectionAction', s, 'down')">
                        <VmIcon name="arrow-down" />
                    </button>
                    <button type="button" class="vm-ed-iconbtn" :aria-label="t('editor.blockTools.duplicate')" :title="t('editor.blockTools.duplicate')" @click.stop="emit('sectionAction', s, 'duplicate')">
                        <VmIcon name="copy" />
                    </button>
                    <button type="button" class="vm-ed-iconbtn" :aria-label="t('editor.blockTools.save')" :title="t('editor.blockTools.save')" :data-testid="`save-section-${s}`" @click.stop="emit('sectionAction', s, 'save')">
                        <VmIcon name="plus" />
                    </button>
                    <button type="button" class="vm-ed-iconbtn" :aria-label="t('editor.blockTools.delete')" :title="t('editor.blockTools.delete')" :data-testid="`delete-section-${s}`" @click.stop="emit('sectionAction', s, 'delete')">
                        <VmIcon name="trash" />
                    </button>
                </div>

                <span v-if="section.attrs?.condition" class="vm-ed-badge"><VmIcon name="condition" />{{ t('editor.condition.sectionBadge') }}</span>

                <div class="vm-ed-columns">
                    <div
                        v-for="(column, c) in section.children ?? []"
                        :key="c"
                        class="vm-ed-column"
                        :class="{ 'vm-ed-column--empty': !(column.children ?? []).length }"
                        :style="{ flex: columnFlex(column), background: column.attrs?.background ? String(column.attrs.background) : undefined, color: styles.text_color }"
                        :data-testid="`column-${s}-${c}`"
                        @dragover="overColumn($event, s, c, (column.children ?? []).length)"
                    >
                        <template v-if="(column.children ?? []).length">
                            <template v-for="(block, b) in column.children" :key="b">
                                <div class="vm-ed-drop" :class="{ 'vm-ed-drop--active': isBlockTarget(s, c, b) }" />
                                <CanvasBlock
                                    :block="block"
                                    :path="{ s, c, b }"
                                    :selected="isSelectedBlock(s, c, b)"
                                    :styles="styles"
                                    :catalog="catalog"
                                    :first="s === 0 && b === 0"
                                    :last="s === blocks.length - 1 && b === (column.children ?? []).length - 1"
                                    @select="emit('select', { kind: 'block', s, c, b })"
                                    @update="(patch, key) => emit('update', { s, c, b }, patch, key)"
                                    @action="(action) => emit('blockAction', { s, c, b }, action)"
                                    @dragstart="startBlockDrag($event, { s, c, b })"
                                    @dragover="overBlock($event, s, c, b)"
                                />
                            </template>
                            <div class="vm-ed-drop" :class="{ 'vm-ed-drop--active': isBlockTarget(s, c, (column.children ?? []).length) }" />
                        </template>
                        <span v-else :class="{ 'vm-ed-drop--active': isBlockTarget(s, c, 0) }">{{ t('editor.canvas.emptyColumn') }}</span>
                    </div>
                </div>
            </section>
        </template>

        <div class="vm-ed-drop vm-ed-drop--section" :class="{ 'vm-ed-drop--active': isSectionTarget(blocks.length) }" data-testid="drop-section-end" />

        <div v-if="!blocks.length" class="vm-ed-dropzone" :class="{ 'vm-ed-dropzone--active': isSectionTarget(0) }" data-testid="canvas-empty">
            {{ t('editor.canvas.empty') }}
        </div>
    </div>
</template>
