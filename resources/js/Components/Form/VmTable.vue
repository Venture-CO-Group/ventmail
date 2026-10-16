<script setup lang="ts" generic="Row extends Record<string, unknown>">
import { VtDataTable, type VtColumn } from '@venture-co-group/ui';
import { computed } from 'vue';

/**
 * TÁBLA — a közös `VtDataTable` (Figma DataTable, Unified UI v2 · 5. szabály) adaptere a
 * ventMail soraihoz: a sorok nem hordoznak saját azonosítót, ezért a kulcs a sorszám.
 *
 * A cella tartalma egy sorba vágódik (`…`), a teljes érték a `title`-ben marad; ami így sem
 * fér, azt a tábla maga görgeti vízszintesen. Az egyedi cella (`#cell`) továbbra is
 * használható — akkor a hívó felel a vágásért (`vm-truncate`).
 *
 * A tábla a kártyájában áll, amelynek címe megnevezi: a `caption` ezért nem látható felirat,
 * hanem a régió akadálymentes neve (`aria-label`).
 */
const props = defineProps<{
    columns: VtColumn<Row>[];
    rows: Row[];
    caption?: string;
}>();

type Keyed = Row & { __vmKey: string };

const keyed = computed(() => props.rows.map((row, index) => ({ ...row, __vmKey: String(index) }) as Keyed));
const cols = computed(() => props.columns as unknown as VtColumn<Keyed>[]);

/** A hívó a saját sortípusát látja a `#cell` slotban (a kulcs-mező nélkül). */
const forward = (slot: unknown) => slot as { row: Row; column: VtColumn<Row>; value: unknown };
</script>

<template>
    <VtDataTable class="vm-table" :columns="cols" :rows="keyed" row-key="__vmKey" role="region" :aria-label="caption">
        <template #cell="slotProps">
            <slot name="cell" v-bind="forward(slotProps)">
                <span
                    class="vm-truncate"
                    :class="{ 'vm-truncate--end': slotProps.column.align === 'right',
                        'vm-truncate--center': slotProps.column.align === 'center',
                    }"
                    :title="String(slotProps.value ?? '')"
                >{{ slotProps.value }}</span>
            </slot>
        </template>
    </VtDataTable>
</template>
