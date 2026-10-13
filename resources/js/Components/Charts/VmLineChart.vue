<script setup lang="ts">
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { computed, onMounted, ref } from 'vue';
import VChart from 'vue-echarts';

/**
 * IDŐSOR-GRAFIKON (v1.1 · 7. modul, D6) — ECharts (Apache-2.0) + vue-echarts (MIT),
 * tree-shakelt importtal (csak vonal + rács + tooltip + jelmagyarázat + canvas).
 *
 * SZÍN CSAK TOKENBŐL: a sorozat egy `--vt-*` token NEVÉT kapja, az értéket a mount után a
 * dokumentum számított stílusából olvassuk (a canvas CSS-változót nem ért). Így a grafikon
 * a sötét témán is a Venture palettát használja, és nincs beégetett szín.
 *
 * Újrahasznosítható: a 8. modul (analitika) erre építi a többi grafikont és a PNG-exportot.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: chart (idősor).
 */
use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

export interface VmChartSeries {
    name: string;
    data: number[];
    /** A szín token NEVE, pl. `--vt-color-accent-500`. */
    token: string;
}

const props = withDefaults(defineProps<{ categories: string[]; series: VmChartSeries[]; label: string; testid?: string }>(), {
    testid: undefined,
});

const palette = ref<Record<string, string>>({});
const text = ref('');
const grid = ref('');
const font = ref('');

onMounted(() => {
    const style = getComputedStyle(document.documentElement);
    const read = (name: string): string => style.getPropertyValue(name).trim();

    palette.value = Object.fromEntries(props.series.map((serie) => [serie.token, read(serie.token)]));
    text.value = read('--vt-text-muted');
    grid.value = read('--vt-border');
    font.value = read('--vt-font-body');
});

const option = computed(() => ({
    animation: false,
    textStyle: { color: text.value, fontFamily: font.value || undefined },
    tooltip: { trigger: 'axis' },
    legend: { top: 0, textStyle: { color: text.value } },
    grid: { left: 8, right: 8, top: 36, bottom: 8, containLabel: true },
    xAxis: {
        type: 'category',
        boundaryGap: false,
        data: props.categories,
        axisLine: { lineStyle: { color: grid.value } },
        axisLabel: { color: text.value },
    },
    yAxis: {
        type: 'value',
        minInterval: 1,
        splitLine: { lineStyle: { color: grid.value } },
        axisLabel: { color: text.value },
    },
    series: props.series.map((serie) => ({
        name: serie.name,
        type: 'line',
        smooth: true,
        showSymbol: props.categories.length < 40,
        data: serie.data,
        itemStyle: { color: palette.value[serie.token] || undefined },
        lineStyle: { color: palette.value[serie.token] || undefined },
    })),
}));
</script>

<template>
    <div class="vm-chart" role="img" :aria-label="label" :data-testid="testid">
        <VChart :option="option" autoresize class="vm-chart__canvas" />
    </div>
</template>

<style scoped>
.vm-chart {
    width: 100%;
    min-width: 0;
}

.vm-chart__canvas {
    width: 100%;
    height: calc(var(--vt-space-32) * 2.5);
}
</style>
