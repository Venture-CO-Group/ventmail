<script setup lang="ts">
import { ref } from 'vue';
import type { ExportBackground, TokenResolver } from './chartTheme';
import VmChart from './VmChart.vue';

/** HALMOZOTT OSZLOP-GRAFIKON (v1.1 · 8. modul) — pl. új / leiratkozott + nettó vonal. */
export interface VmBarSeries {
    name: string;
    data: number[];
    token: string;
    stack?: string;
    /** Vonalként rajzolva ugyanazon a tengelyen (pl. a nettó változás). */
    line?: boolean;
}

const props = withDefaults(
    defineProps<{ categories: string[]; series: VmBarSeries[]; label: string; testid?: string; size?: 'sm' | 'md' | 'lg' }>(),
    { testid: undefined, size: 'md' },
);

const inner = ref<InstanceType<typeof VmChart> | null>(null);

function build(token: TokenResolver): Record<string, unknown> {
    const text = token('--vt-text-muted');
    const grid = token('--vt-border');

    return {
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        legend: { top: 0, textStyle: { color: text } },
        grid: { left: 8, right: 24, top: 36, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: props.categories, axisLine: { lineStyle: { color: grid } }, axisLabel: { color: text } },
        yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: grid } }, axisLabel: { color: text } },
        series: props.series.map((serie) => ({
            name: serie.name,
            type: serie.line ? 'line' : 'bar',
            stack: serie.line ? undefined : serie.stack,
            smooth: serie.line ? true : undefined,
            showSymbol: false,
            barMaxWidth: 24,
            data: serie.data,
            itemStyle: { color: token(serie.token) || undefined, borderRadius: serie.line ? undefined : 2 },
            lineStyle: serie.line ? { color: token(serie.token) || undefined } : undefined,
        })),
    };
}

defineExpose({ toPng: (background: ExportBackground) => inner.value?.toPng(background) ?? null });
</script>

<template>
    <VmChart ref="inner" :option="build" :label="label" :testid="testid" :size="size" />
</template>
