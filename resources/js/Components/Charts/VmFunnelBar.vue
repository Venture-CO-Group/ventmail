<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ExportBackground, TokenResolver } from './chartTheme';
import VmChart from './VmChart.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * VÍZSZINTES KONVERZIÓS SÁV (v1.1 · 8. modul) — journey-csomópontok: a sáv a belépett, a
 * felirat a konverzió. Felülről lefelé a bejárás sorrendjében.
 */
export interface VmFunnelRow {
    name: string;
    value: number;
    note?: string;
}

const props = withDefaults(defineProps<{ rows: VmFunnelRow[]; label: string; testid?: string }>(), { testid: undefined });

const inner = ref<InstanceType<typeof VmChart> | null>(null);
// A tengely- és tooltip-számok a profil formátumában (D284).
const format = useFormat();
const size = computed(() => (props.rows.length > 8 ? 'lg' : 'md'));

function build(token: TokenResolver): Record<string, unknown> {
    const text = token('--vt-text-primary');
    const muted = token('--vt-text-secondary');
    const grid = token('--vt-border');
    const rows = [...props.rows].reverse();

    return {
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: (value: number) => format.number(value, { maximumFractionDigits: 2 }) },
        grid: { left: 8, right: 48, top: 8, bottom: 8, containLabel: true },
        xAxis: { type: 'value', splitLine: { lineStyle: { color: grid } }, axisLabel: { color: muted, formatter: (value: number) => format.number(value, { maximumFractionDigits: 2 }) } },
        yAxis: { type: 'category', data: rows.map((row) => row.name), axisLabel: { color: text, width: 160, overflow: 'truncate' }, axisLine: { lineStyle: { color: grid } } },
        series: [
            {
                type: 'bar',
                barMaxWidth: 16,
                data: rows.map((row) => row.value),
                itemStyle: { color: token('--vt-accent') || undefined, borderRadius: 4 },
                label: { show: true, position: 'right', color: muted, formatter: (params: { dataIndex: number }) => rows[params.dataIndex]?.note ?? '' },
            },
        ],
    };
}

defineExpose({ toPng: (background: ExportBackground) => inner.value?.toPng(background) ?? null });
</script>

<template>
    <VmChart ref="inner" :option="build" :label="label" :testid="testid" :size="size" />
</template>
