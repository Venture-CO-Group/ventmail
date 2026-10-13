<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import { dragging } from './dragState';
import type { DragPayload, EditorCatalog, SavedBlockDef } from './types';
import { DRAG_MIME } from './types';

/*
 | HOZZÁADÁS: Content (blokkok + mentett blokkok) és Layouts (oszlopok + minták) fül.
 |
 | Minden csempe HÚZHATÓ (natív DnD) ÉS gomb: kattintásra / Enterre a kijelölés után (vagy a
 | levél végére) kerül — ez a billentyűzetes és érintős alternatíva.
 */
defineProps<{ catalog: EditorCatalog; savedBlocks: SavedBlockDef[] }>();

const emit = defineEmits<{ add: [payload: DragPayload]; dragend: [] }>();

const { t } = useI18n();
const tab = ref<'content' | 'layouts'>('content');

function start(event: DragEvent, payload: DragPayload): void {
    dragging.value = payload;
    event.dataTransfer?.setData(DRAG_MIME, JSON.stringify(payload));

    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'copy';
}

function end(): void {
    dragging.value = null;
    emit('dragend');
}

function savedIcon(block: SavedBlockDef): string {
    return block.kind === 'section' ? 'columns' : 'plus';
}
</script>

<template>
    <div class="vm-stack" data-testid="palette">
        <div class="vm-ed-tabs" role="tablist" :aria-label="t('editor.rail.add')">
            <button type="button" role="tab" :aria-selected="tab === 'content'" data-testid="palette-tab-content" @click="tab = 'content'">
                {{ t('editor.palette.content') }}
            </button>
            <button type="button" role="tab" :aria-selected="tab === 'layouts'" data-testid="palette-tab-layouts" @click="tab = 'layouts'">
                {{ t('editor.palette.layouts') }}
            </button>
        </div>

        <template v-if="tab === 'content'">
            <div class="vm-ed-palette" role="list">
                <button
                    v-for="item in catalog.blocks"
                    :key="item.id"
                    type="button"
                    role="listitem"
                    class="vm-ed-tile"
                    draggable="true"
                    :data-testid="`palette-${item.id}`"
                    @dragstart="start($event, { source: 'palette', id: item.id })"
                    @dragend="end"
                    @click="emit('add', { source: 'palette', id: item.id })"
                >
                    <VmIcon :name="item.icon" />
                    <span>{{ t(`editor.blocks.${item.id}`) }}</span>
                </button>
            </div>
            <p class="vm-ed-hint">{{ t('editor.palette.hint') }}</p>

            <template v-if="savedBlocks.length">
                <p class="vm-ed-heading">{{ t('editor.palette.saved') }}</p>
                <div class="vm-ed-palette" role="list">
                    <button
                        v-for="item in savedBlocks"
                        :key="item.uuid"
                        type="button"
                        role="listitem"
                        class="vm-ed-tile"
                        draggable="true"
                        :data-testid="`palette-saved-${item.uuid}`"
                        @dragstart="start($event, { source: 'saved', uuid: item.uuid })"
                        @dragend="end"
                        @click="emit('add', { source: 'saved', uuid: item.uuid })"
                    >
                        <VmIcon :name="savedIcon(item)" />
                        <span>{{ item.name }}</span>
                    </button>
                </div>
            </template>
        </template>

        <template v-else>
            <p class="vm-ed-heading">{{ t('editor.palette.columns') }}</p>
            <div class="vm-ed-palette" role="list">
                <button
                    v-for="layout in catalog.layouts"
                    :key="layout.key"
                    type="button"
                    role="listitem"
                    class="vm-ed-tile"
                    draggable="true"
                    :data-testid="`layout-${layout.key}`"
                    @dragstart="start($event, { source: 'layout', key: layout.key })"
                    @dragend="end"
                    @click="emit('add', { source: 'layout', key: layout.key })"
                >
                    <span class="vm-ed-layout" aria-hidden="true">
                        <span v-for="(width, index) in layout.columns" :key="index" :style="{ flex: `${parseFloat(width)} 1 0` }" />
                    </span>
                    <span>{{ t(`editor.layouts.c${layout.key.replace('-', '_')}`) }}</span>
                </button>
            </div>

            <p class="vm-ed-heading">{{ t('editor.palette.patterns') }}</p>
            <div class="vm-ed-palette" role="list">
                <button
                    v-for="pattern in catalog.patterns"
                    :key="pattern.key"
                    type="button"
                    role="listitem"
                    class="vm-ed-tile"
                    draggable="true"
                    :data-testid="`pattern-${pattern.key}`"
                    @dragstart="start($event, { source: 'pattern', key: pattern.key })"
                    @dragend="end"
                    @click="emit('add', { source: 'pattern', key: pattern.key })"
                >
                    <VmIcon :name="pattern.key === 'footer' ? 'footer' : pattern.key === 'hero' ? 'image' : 'layout'" />
                    <span>{{ t(`editor.patterns.${pattern.key}`) }}</span>
                </button>
            </div>
        </template>
    </div>
</template>
