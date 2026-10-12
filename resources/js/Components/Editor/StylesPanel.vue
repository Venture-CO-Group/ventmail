<script setup lang="ts">
import { VtField, VtSelect } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EdColor from './EdColor.vue';
import type { Resolved } from './render';
import type { EditorCatalog, TemplateStyles } from './types';

/*
 | STÍLUSOK: a levél GLOBÁLIS betűje, színei, gombja, háttere és szélessége. Minden érték a
 | szerver által ismert, típusos kulcs (`TemplateStyles`) — szabad CSS nincs.
 |
 | JELENTÉS: hiányzó @venture/ui komponens: csúszka (`VtRange`) — itt natív `range` mező.
 */
const props = defineProps<{ styles: Resolved; catalog: EditorCatalog }>();

const emit = defineEmits<{ update: [patch: Partial<TemplateStyles>] }>();

const { t } = useI18n();

const fontOptions = computed(() => props.catalog.fonts.map((font) => ({ value: font, label: t(`editor.fonts.${font}`) })));

function range(key: 'font_size' | 'button_radius' | 'width'): [number, number] {
    return props.catalog.styleRanges[key] ?? [0, 100];
}

function number(key: 'font_size' | 'button_radius' | 'width', event: Event): void {
    emit('update', { [key]: Number((event.target as HTMLInputElement).value) });
}
</script>

<template>
    <div class="vm-ed-fields" data-testid="styles-panel">
        <p class="vm-ed-heading">{{ t('editor.styles.typography') }}</p>
        <VtField :label="t('editor.styles.font')">
            <VtSelect :model-value="styles.font" :options="fontOptions" data-testid="style-font" @update:model-value="emit('update', { font: String($event) })" />
        </VtField>
        <label class="vm-stack">
            <span>{{ t('editor.styles.fontSize', { value: styles.font_size }) }}</span>
            <input class="vm-ed-range" type="range" :min="range('font_size')[0]" :max="range('font_size')[1]" :value="styles.font_size" data-testid="style-font-size" @input="number('font_size', $event)" />
        </label>

        <p class="vm-ed-heading">{{ t('editor.styles.colors') }}</p>
        <EdColor :model-value="styles.text_color" :swatches="catalog.swatches" :label="t('editor.styles.textColor')" testid="style-text-color" @update:model-value="emit('update', { text_color: $event ?? undefined })" />
        <EdColor :model-value="styles.heading_color" :swatches="catalog.swatches" :label="t('editor.styles.headingColor')" @update:model-value="emit('update', { heading_color: $event ?? undefined })" />
        <EdColor :model-value="styles.link_color" :swatches="catalog.swatches" :label="t('editor.styles.linkColor')" @update:model-value="emit('update', { link_color: $event ?? undefined })" />
        <EdColor :model-value="styles.content_background" :swatches="catalog.swatches" :label="t('editor.styles.contentBackground')" @update:model-value="emit('update', { content_background: $event ?? undefined })" />
        <EdColor :model-value="styles.background" :swatches="catalog.swatches" :label="t('editor.styles.background')" testid="style-background" @update:model-value="emit('update', { background: $event ?? undefined })" />

        <p class="vm-ed-heading">{{ t('editor.styles.button') }}</p>
        <EdColor :model-value="styles.button_background" :swatches="catalog.swatches" :label="t('editor.styles.buttonBackground')" testid="style-button-background" @update:model-value="emit('update', { button_background: $event ?? undefined })" />
        <EdColor :model-value="styles.button_color" :swatches="catalog.swatches" :label="t('editor.styles.buttonColor')" @update:model-value="emit('update', { button_color: $event ?? undefined })" />
        <label class="vm-stack">
            <span>{{ t('editor.styles.buttonRadius', { value: styles.button_radius }) }}</span>
            <input class="vm-ed-range" type="range" :min="range('button_radius')[0]" :max="range('button_radius')[1]" :value="styles.button_radius" @input="number('button_radius', $event)" />
        </label>

        <p class="vm-ed-heading">{{ t('editor.styles.layout') }}</p>
        <label class="vm-stack">
            <span>{{ t('editor.styles.width', { value: styles.width }) }}</span>
            <input class="vm-ed-range" type="range" step="10" :min="range('width')[0]" :max="range('width')[1]" :value="styles.width" data-testid="style-width" @input="number('width', $event)" />
        </label>
    </div>
</template>
