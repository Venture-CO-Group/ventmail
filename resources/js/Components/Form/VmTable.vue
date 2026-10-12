<script setup lang="ts" generic="Row extends Record<string, unknown>">
import { VtTable, type VtColumn } from '@venture/ui';

/**
 * TÁBLA hosszú értékekhez: a cella tartalma egy sorba vágódik (`…`), a teljes érték a
 * `title`-ben (egérre és képernyőolvasónak) marad; ami még így sem fér, azt a tábla
 * MAGA görgeti vízszintesen — nem csúszik ki a kártyából, és nem vágódik le némán.
 *
 * Az egyedi cella (`#cell`) továbbra is használható: akkor a hívó felel a vágásért
 * (`vm-truncate` osztály).
 *
 * JELENTÉS a @venture/ui felé: a `VtTable` cellái ellipszis-vágást és `title`-t, a
 * gyökere pedig vízszintes görgetést kapjanak alapból.
 */
defineProps<{
    columns: VtColumn<Row>[];
    rows: Row[];
    glass?: boolean;
    caption?: string;
}>();
</script>

<template>
    <VtTable class="vm-table" :columns="columns" :rows="rows" :glass="glass" :caption="caption">
        <template #cell="slotProps">
            <slot name="cell" v-bind="slotProps">
                <span class="vm-truncate" :title="String(slotProps.value ?? '')">{{ slotProps.value }}</span>
            </slot>
        </template>
    </VtTable>
</template>
