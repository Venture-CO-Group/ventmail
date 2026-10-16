<script setup lang="ts">
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { computed, onMounted, ref } from 'vue';
import VChart from 'vue-echarts';
import { darkBackground, tokenResolver, type ExportBackground, type TokenResolver } from './chartTheme';

/**
 * GRAFIKON-ALAP (v1.1 · 8. modul, D6) — ECharts (Apache-2.0) + vue-echarts (MIT), tree-shakelt
 * importtal (vonal, oszlop, kör + rács, tooltip, jelmagyarázat, jelölővonal + canvas).
 *
 * Az `option` egy FÜGGVÉNY, ami a token-feloldót kapja: a szín mindig `--vt-*` tokenből jön, az
 * értékét a mount után olvassuk (a canvas CSS-változót nem ért). A wrapperek (`VmLineChart`,
 * `VmBarChart`, `VmDonutChart`, `VmFunnelBar`) csak az opciót építik.
 *
 * PNG-EXPORT: `toPng('transparent' | 'dark')` → 2× felbontású dataURL (`getDataURL`), átlátszó
 * vagy a sötét téma háttérszínével. Szerverhívás nincs.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: chart (idősor, oszlop, kör, tölcsér) + export-menü.
 */
use([LineChart, BarChart, PieChart, GridComponent, TooltipComponent, LegendComponent, MarkLineComponent, CanvasRenderer]);

type ChartOption = Record<string, unknown>;

const props = withDefaults(
    defineProps<{
        option: (token: TokenResolver) => ChartOption;
        label: string;
        testid?: string;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { testid: undefined, size: 'md' },
);

const chart = ref<InstanceType<typeof VChart> | null>(null);
const resolver = ref<TokenResolver>(() => '');

onMounted(() => {
    resolver.value = tokenResolver();
});

const option = computed<ChartOption>(() => {
    const token = resolver.value;
    const text = token('--vt-text-secondary');
    const font = token('--vt-font-body');

    return {
        animation: false,
        textStyle: { color: text || undefined, fontFamily: font || undefined },
        ...props.option(token),
    };
});

function toPng(background: ExportBackground): string | null {
    const instance = chart.value as unknown as { getDataURL?: (opts: Record<string, unknown>) => string } | null;

    if (!instance?.getDataURL) return null;

    return instance.getDataURL({
        type: 'png',
        pixelRatio: 2,
        backgroundColor: background === 'dark' ? darkBackground() : 'transparent',
    });
}

defineExpose({ toPng });
</script>

<template>
    <div class="vm-chart" :class="`vm-chart--${size}`" role="img" :aria-label="label" :data-testid="testid">
        <VChart ref="chart" :option="option" autoresize class="vm-chart__canvas" />
    </div>
</template>

<style scoped>
.vm-chart {
    width: 100%;
    min-width: 0;
}

.vm-chart__canvas {
    width: 100%;
    height: calc(calc(var(--vt-space-8) * 4) * 2.5);
}

.vm-chart--sm .vm-chart__canvas {
    height: calc(calc(var(--vt-space-8) * 4) * 1.5);
}

.vm-chart--lg .vm-chart__canvas {
    height: calc(calc(var(--vt-space-8) * 4) * 3);
}
</style>
