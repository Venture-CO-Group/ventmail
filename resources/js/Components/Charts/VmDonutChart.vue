<script setup lang="ts">
import { ref } from 'vue';
import { CATEGORY_TOKENS, type ExportBackground, type TokenResolver } from './chartTheme';
import VmChart from './VmChart.vue';

/** KÖR-/FÁNK-GRAFIKON (v1.1 · 8. modul) — eszköz, kliens, bounce-típus, engagement-szint. */
export interface VmDonutItem {
    name: string;
    value: number;
    token?: string;
}

const props = withDefaults(
    defineProps<{ items: VmDonutItem[]; label: string; center?: string; centerCaption?: string; testid?: string; size?: 'sm' | 'md' | 'lg' }>(),
    { center: '', centerCaption: '', testid: undefined, size: 'sm' },
);

const inner = ref<InstanceType<typeof VmChart> | null>(null);

function build(token: TokenResolver): Record<string, unknown> {
    const text = token('--vt-text-primary');
    const muted = token('--vt-text-secondary');

    return {
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        title: undefined,
        graphic: undefined,
        series: [
            {
                type: 'pie',
                radius: ['58%', '82%'],
                avoidLabelOverlap: true,
                label: props.center
                    ? { show: true, position: 'center', formatter: () => `{v|${props.center}}\n{c|${props.centerCaption}}`, rich: { v: { color: text, fontSize: 18, fontWeight: 600 }, c: { color: muted, fontSize: 11 } } }
                    : { show: false },
                labelLine: { show: false },
                emphasis: { scale: false },
                data: props.items.map((item, index) => ({
                    name: item.name,
                    value: item.value,
                    itemStyle: { color: token(item.token ?? CATEGORY_TOKENS[index % CATEGORY_TOKENS.length]) || undefined },
                })),
            },
        ],
    };
}

defineExpose({ toPng: (background: ExportBackground) => inner.value?.toPng(background) ?? null });
</script>

<template>
    <VmChart ref="inner" :option="build" :label="label" :testid="testid" :size="size" />
</template>
