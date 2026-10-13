<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '../../composables/useFormat';

/**
 * NAPI NÖVEKEDÉS oszlopokkal (v1.1 · 5. modul): felfelé az új, lefelé a leiratkozott.
 *
 * Token-színezett, könyvtár nélküli közelítés — a 8. modul ECharts-a váltja (a bemenet
 * ugyanaz a `[{date, new, unsubscribed, net}]` sorlista). Képernyőolvasónak egy rejtett
 * tábla adja ugyanezt a számsort.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: oszlopdiagram / mini-chart.
 */
export interface GrowthRow {
    date: string;
    new: number;
    unsubscribed: number;
    net: number;
}

const props = defineProps<{ rows: GrowthRow[] }>();

const { t } = useI18n();
const format = useFormat();

const max = computed(() => Math.max(1, ...props.rows.map((row) => Math.max(row.new, row.unsubscribed))));

const columns = computed(() =>
    props.rows.map((row) => ({
        ...row,
        up: Math.round((row.new / max.value) * 100),
        down: Math.round((row.unsubscribed / max.value) * 100),
        title: `${row.date}: +${row.new} / −${row.unsubscribed} (${row.net >= 0 ? '+' : ''}${row.net})`,
    })),
);
</script>

<template>
    <div class="vm-growth" data-testid="insights-growth">
        <div class="vm-growth__plot" aria-hidden="true">
            <div v-for="column in columns" :key="column.date" class="vm-growth__column" :title="column.title">
                <div class="vm-growth__half vm-growth__half--up">
                    <span class="vm-growth__bar vm-growth__bar--new" :style="{ blockSize: `${column.up}%` }" />
                </div>
                <div class="vm-growth__half vm-growth__half--down">
                    <span class="vm-growth__bar vm-growth__bar--lost" :style="{ blockSize: `${column.down}%` }" />
                </div>
            </div>
        </div>

        <div class="vm-growth__axis" aria-hidden="true">
            <span>{{ rows[0] ? format.day(rows[0].date) : '' }}</span>
            <span>{{ rows.length ? format.day(rows[rows.length - 1].date) : '' }}</span>
        </div>

        <!-- A TÁBLA nem zsugorodik a rejtő osztály méretére (auto layout) — ezért egy burkoló rejti. -->
        <div class="vm-visually-hidden">
        <table>
            <caption>{{ t('insights.growth') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('insights.date') }}</th>
                    <th scope="col">{{ t('insights.new') }}</th>
                    <th scope="col">{{ t('insights.unsubscribed') }}</th>
                    <th scope="col">{{ t('insights.net') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.date">
                    <td>{{ format.day(row.date) }}</td>
                    <td>{{ row.new }}</td>
                    <td>{{ row.unsubscribed }}</td>
                    <td>{{ row.net }}</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</template>

<style scoped>
.vm-growth {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-growth__plot {
    display: flex;
    align-items: stretch;
    gap: var(--vt-space-1);
    block-size: calc(var(--vt-space-32) * 1.5);
}

.vm-growth__column {
    display: flex;
    flex: 1 1 0;
    flex-direction: column;
    min-inline-size: 0;
}

.vm-growth__half {
    display: flex;
    flex: 1 1 0;
}

.vm-growth__half--up {
    flex-grow: 3;
    align-items: flex-end;
    border-block-end: thin solid var(--vt-border);
}

.vm-growth__half--down {
    align-items: flex-start;
}

.vm-growth__bar {
    inline-size: 100%;
    border-radius: var(--vt-radius-sm);
}

.vm-growth__bar--new {
    background-image: var(--vm-glow-linear);
}

.vm-growth__bar--lost {
    background-color: var(--vt-color-danger);
}

.vm-growth__axis {
    display: flex;
    justify-content: space-between;
    gap: var(--vt-space-2);
    font-family: var(--vt-font-mono);
    font-size: var(--vt-fs-caption);
    color: var(--vt-text-subtle);
}
</style>
