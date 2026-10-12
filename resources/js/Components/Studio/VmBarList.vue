<script setup lang="ts">
import { computed } from 'vue';

/**
 * ARÁNY-SÁV + JELMAGYARÁZAT (v1.1 · 5. modul) — forrás- és engagement-bontás.
 *
 * Egyszerű, token-színezett halmozott sáv; a 8. modul ECharts-a váltja (a bemenet ugyanaz:
 * `[{key, label, count}]`). A számok szövegként is ott vannak (a jelmagyarázatban), tehát
 * a sáv díszítés — képernyőolvasónak a lista a tartalom.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: arány-sáv / mini-chart.
 */
export interface BarItem {
    key: string;
    label: string;
    count: number;
}

const props = defineProps<{
    items: BarItem[];
    testid?: string;
}>();

const total = computed(() => props.items.reduce((sum, item) => sum + item.count, 0));

const rows = computed(() =>
    props.items.map((item, index) => ({
        ...item,
        tone: `vm-bars__tone--${index % 5}`,
        percent: total.value > 0 ? Math.round((item.count / total.value) * 1000) / 10 : 0,
    })),
);
</script>

<template>
    <div class="vm-bars" :data-testid="testid">
        <div class="vm-bars__track" aria-hidden="true">
            <span
                v-for="row in rows"
                v-show="row.count > 0"
                :key="row.key"
                class="vm-bars__segment"
                :class="row.tone"
                :style="{ flexGrow: row.count }"
            />
        </div>

        <ul class="vm-bars__legend">
            <li v-for="row in rows" :key="row.key" class="vm-bars__row" :data-testid="testid ? `${testid}-${row.key}` : undefined">
                <span class="vm-bars__dot" :class="row.tone" aria-hidden="true" />
                <span class="vm-bars__label vm-truncate" :title="row.label">{{ row.label }}</span>
                <span class="vm-bars__count">{{ row.count.toLocaleString() }}</span>
                <span class="vm-bars__percent">{{ row.percent }}%</span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.vm-bars {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

.vm-bars__track {
    display: flex;
    gap: var(--vt-space-1);
    block-size: var(--vt-space-2);
    border-radius: var(--vt-radius-full);
    background-color: var(--vt-glass-thin-fill);
    overflow: hidden;
}

.vm-bars__segment {
    flex-basis: 0;
    block-size: 100%;
}

.vm-bars__legend {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-bars__row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto auto;
    align-items: center;
    gap: var(--vt-space-3);
    font-size: var(--vt-fs-body-sm);
}

.vm-bars__dot {
    inline-size: var(--vt-space-2);
    block-size: var(--vt-space-2);
    border-radius: var(--vt-radius-full);
}

.vm-bars__label {
    color: var(--vt-text-muted);
}

.vm-bars__count {
    font-family: var(--vt-font-mono);
}

.vm-bars__percent {
    font-weight: var(--vt-weight-semibold);
    min-inline-size: var(--vt-space-12);
    text-align: end;
}

.vm-bars__tone--0 {
    background-color: var(--vt-accent);
}

.vm-bars__tone--1 {
    background-color: var(--vt-color-cyan-400);
}

.vm-bars__tone--2 {
    background-color: var(--vt-color-violet-400);
}

.vm-bars__tone--3 {
    background-color: var(--vt-color-success);
}

.vm-bars__tone--4 {
    background-color: var(--vt-color-warning);
}
</style>
