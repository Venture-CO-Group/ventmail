<script setup lang="ts">
import { ref } from 'vue';
import type { ExportBackground, TokenResolver } from './chartTheme';
import VmChart from './VmChart.vue';

/**
 * IDŐSOR-GRAFIKON (v1.1 · 7. modul, D6; a 8. modulban a `VmChart` alapra költözött — az API
 * változatlan). Opcionálisan százalék-tengely és jelölővonal (pl. a 0,3%-os panasz-küszöb).
 */
export interface VmChartSeries {
    name: string;
    data: number[];
    /** A szín token NEVE, pl. `--vt-color-accent-500`. */
    token: string;
    area?: boolean;
}

const props = withDefaults(
    defineProps<{
        categories: string[];
        series: VmChartSeries[];
        label: string;
        testid?: string;
        percent?: boolean;
        markLine?: { value: number; name: string; token: string; seriesIndex?: number } | null;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { testid: undefined, percent: false, markLine: null, size: 'md' },
);

const inner = ref<InstanceType<typeof VmChart> | null>(null);

function build(token: TokenResolver): Record<string, unknown> {
    const text = token('--vt-text-muted');
    const grid = token('--vt-border');
    const formatter = props.percent ? '{value}%' : '{value}';

    return {
        tooltip: { trigger: 'axis', valueFormatter: (value: number) => (props.percent ? `${value}%` : String(value)) },
        legend: { top: 0, textStyle: { color: text } },
        grid: { left: 8, right: 24, top: 36, bottom: 8, containLabel: true },
        xAxis: { type: 'category', boundaryGap: false, data: props.categories, axisLine: { lineStyle: { color: grid } }, axisLabel: { color: text } },
        yAxis: { type: 'value', minInterval: props.percent ? undefined : 1, splitLine: { lineStyle: { color: grid } }, axisLabel: { color: text, formatter } },
        series: props.series.map((serie, index) => ({
            name: serie.name,
            type: 'line',
            smooth: true,
            showSymbol: props.categories.length < 40,
            data: serie.data,
            itemStyle: { color: token(serie.token) || undefined },
            lineStyle: { color: token(serie.token) || undefined },
            areaStyle: serie.area ? { color: token(serie.token) || undefined, opacity: 0.15 } : undefined,
            markLine:
                props.markLine && (props.markLine.seriesIndex ?? 0) === index
                    ? {
                          symbol: 'none',
                          silent: true,
                          label: { formatter: props.markLine.name, color: text, position: 'insideEndTop' },
                          lineStyle: { color: token(props.markLine.token), type: 'dashed' },
                          data: [{ yAxis: props.markLine.value }],
                      }
                    : undefined,
        })),
    };
}

defineExpose({ toPng: (background: ExportBackground) => inner.value?.toPng(background) ?? null });
</script>

<template>
    <VmChart ref="inner" :option="build" :label="label" :testid="testid" :size="size" />
</template>
