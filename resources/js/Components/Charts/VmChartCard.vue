<script setup lang="ts">
import VmCard from '../Form/VmCard.vue';
import { downloadDataUrl, slugify, stamp, type ExportableChart, type ExportBackground } from './chartTheme';
import VmChartExportMenu from './VmChartExportMenu.vue';

/**
 * GRAFIKON-KÁRTYA (v1.1 · 8. modul): cím, opcionális alcím és jelmagyarázat, export-menü.
 *
 * A PNG-fájlnév: `<cím-szlag>-<kampány|időszak>-<ÉÉÉÉHHNN>.png`. Ha a grafikon még nem állt
 * össze (nincs adat), a menü nem jelenik meg; a hőtérkép-kártya CSV-linket kap PNG helyett.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        title: string;
        subtitle?: string;
        context?: string;
        chart?: ExportableChart | null;
        csvHref?: string;
        exportable?: boolean;
    }>(),
    { subtitle: undefined, context: '', chart: null, csvHref: undefined, exportable: true },
);

function exportPng(background: ExportBackground): void {
    const url = props.chart?.toPng(background) ?? null;

    if (!url) return;

    const parts = [slugify(props.title), props.context ? slugify(props.context) : '', stamp()].filter(Boolean);
    downloadDataUrl(url, `${parts.join('-')}.png`);
}
</script>

<template>
    <!-- A közös kártyafej (Figma Card): cím + alcím balra, jelmagyarázat és export a fej akcióiban. -->
    <VmCard class="vm-chartcard" :data-testid="`card-${id}`" :aria-labelledby="`card-${id}-title`" role="region">
        <template #header>
            <div class="vm-chartcard__titles">
                <h2 :id="`card-${id}-title`" class="vm-chartcard__title">{{ title }}</h2>
                <span v-if="subtitle" class="vm-chartcard__subtitle">{{ subtitle }}</span>
            </div>
        </template>
        <template v-if="$slots.legend || (exportable && (chart || csvHref))" #actions>
            <div v-if="$slots.legend" class="vm-chartcard__legend"><slot name="legend" /></div>
            <VmChartExportMenu v-if="exportable && (chart || csvHref)" :id="id" :title="title" :csv-href="csvHref" @export="exportPng" />
        </template>
        <slot />
        <p v-if="$slots.note" class="vm-chartcard__note"><slot name="note" /></p>
    </VmCard>
</template>

<style scoped>
.vm-chartcard__titles {
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--vt-space-2);
    min-width: 0;
}

/* A kártyacím a Figma Card címe: Heading/H3. */
.vm-chartcard__title {
    margin: 0;
    color: var(--vt-text-primary);
    font: var(--vt-type-heading-h3);
    letter-spacing: var(--vt-type-heading-h3-tracking);
}

.vm-chartcard__subtitle,
.vm-chartcard__note {
    margin: 0;
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
}

.vm-chartcard__legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-3);
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
}
</style>
