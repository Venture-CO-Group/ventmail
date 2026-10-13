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
    <VmCard glass>
        <section class="vm-chartcard" :data-testid="`card-${id}`" :aria-labelledby="`card-${id}-title`">
            <header class="vm-chartcard__head">
                <div class="vm-chartcard__titles">
                    <h2 :id="`card-${id}-title`" class="vm-chartcard__title">{{ title }}</h2>
                    <span v-if="subtitle" class="vm-chartcard__subtitle">{{ subtitle }}</span>
                </div>
                <div v-if="$slots.legend" class="vm-chartcard__legend"><slot name="legend" /></div>
                <VmChartExportMenu v-if="exportable && (chart || csvHref)" :id="id" :csv-href="csvHref" @export="exportPng" />
            </header>
            <slot />
            <p v-if="$slots.note" class="vm-chartcard__note"><slot name="note" /></p>
        </section>
    </VmCard>
</template>

<style scoped>
.vm-chartcard {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

.vm-chartcard__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-chartcard__titles {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--vt-space-2);
    min-width: 0;
    flex: 1 1 auto;
}

.vm-chartcard__title {
    margin: 0;
    font-family: var(--vt-font-display);
    font-size: var(--vt-fs-h5);
    overflow-wrap: anywhere;
}

.vm-chartcard__subtitle,
.vm-chartcard__note {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
}

.vm-chartcard__legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-3);
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
}
</style>
