<script setup lang="ts">
import { computed } from 'vue';

/**
 * FOLYAMATJELZŐ SÁV (`role="progressbar"`).
 *
 * JELENTÉS: hiányzó @venture/ui komponens: progress bar. Amíg nincs, ez a lokális, kizárólag
 * tokenekből épülő változat áll helyette; a töltés a glow-gradiens (`--vm-glow-linear`).
 */
const props = defineProps<{
    value: number;
    max: number;
    label: string;
}>();

const percent = computed(() => (props.max > 0 ? Math.min(100, Math.round((props.value / props.max) * 100)) : 0));
</script>

<template>
    <div
        class="vm-progress"
        role="progressbar"
        :aria-label="label"
        :aria-valuemin="0"
        :aria-valuemax="100"
        :aria-valuenow="percent"
    >
        <div class="vm-progress__fill" :style="{ inlineSize: `${percent}%` }" />
    </div>
</template>

<style scoped>
.vm-progress {
    inline-size: 100%;
    block-size: var(--vt-space-2);
    border-radius: var(--vt-radius-full);
    background-color: var(--vt-surface-2);
    border: thin solid var(--vt-border);
    overflow: hidden;
}

.vm-progress__fill {
    block-size: 100%;
    border-radius: var(--vt-radius-full);
    background-image: var(--vm-glow-linear);
    transition: inline-size var(--vt-duration-fast) var(--vt-ease-standard);
}
</style>
