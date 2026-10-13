<script setup lang="ts">
import { computed } from 'vue';

/**
 * SPARKLINE (v1.1 · D284) — a KPI-kártya trendvonala. Dekoratív (`aria-hidden`): a számot és a
 * változást a kártya szövege mondja ki, a vonal csak az alakot mutatja.
 *
 * Tiszta SVG (a napi pontok a viewBox 0..100 × 0..30 terébe skálázva); a szín a környezeté
 * (`currentColor`, a kártya tokenje adja). A hiányzó nap (`null`) kimarad a vonalból.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: sparkline / mini-chart.
 */
const props = defineProps<{ values: Array<number | null>; testid?: string }>();

const WIDTH = 100;
const HEIGHT = 30;

const points = computed(() => {
    const defined = props.values.filter((value): value is number => value !== null && Number.isFinite(value));

    if (defined.length < 2) return [] as Array<[number, number]>;

    const min = Math.min(...defined);
    const max = Math.max(...defined);
    const span = max - min || 1;
    const step = WIDTH / Math.max(1, props.values.length - 1);

    return props.values.flatMap((value, index): Array<[number, number]> =>
        value === null || !Number.isFinite(value) ? [] : [[index * step, HEIGHT - 2 - ((value - min) / span) * (HEIGHT - 4)]],
    );
});

const line = computed(() => points.value.map(([x, y]) => `${x.toFixed(2)},${y.toFixed(2)}`).join(' '));
const area = computed(() => {
    if (!points.value.length) return '';

    const first = points.value[0];
    const last = points.value[points.value.length - 1];

    return `M${first[0].toFixed(2)},${HEIGHT} L${line.value.replaceAll(' ', ' L')} L${last[0].toFixed(2)},${HEIGHT} Z`;
});
</script>

<template>
    <svg class="vm-spark" :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" preserveAspectRatio="none" aria-hidden="true" focusable="false" :data-testid="testid">
        <template v-if="points.length">
            <path class="vm-spark__area" :d="area" />
            <polyline class="vm-spark__line" :points="line" vector-effect="non-scaling-stroke" />
        </template>
        <line v-else class="vm-spark__empty" x1="0" :y1="HEIGHT - 2" :x2="WIDTH" :y2="HEIGHT - 2" vector-effect="non-scaling-stroke" />
    </svg>
</template>

<style scoped>
.vm-spark {
    display: block;
    inline-size: 100%;
    block-size: var(--vt-space-8);
    color: var(--vt-accent);
    overflow: visible;
}

.vm-spark__line {
    fill: none;
    stroke: currentColor;
    stroke-width: 1.5;
    stroke-linejoin: round;
    stroke-linecap: round;
}

.vm-spark__area {
    fill: currentColor;
    opacity: 0.16;
}

.vm-spark__empty {
    stroke: var(--vt-border);
    stroke-width: 1;
    stroke-dasharray: 2 3;
}
</style>
