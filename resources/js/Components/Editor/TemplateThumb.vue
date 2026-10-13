<script setup lang="ts">
import { computed } from 'vue';

/*
 | KÖNYVTÁR-BÉLYEG: a sablon vázlata a gyökér-szakaszok hátteréből és oszlopszámából. A
 | levél HTML-jét a listába NEM ágyazzuk be (idegen stílus, sok iframe) — a vázlat a
 | szerver által kiszámolt összefoglalóból rajzolódik. A színek a sablon ADATAI.
 */
interface Row {
    background: string | null;
    columns: number;
    kinds: string[];
}

const props = defineProps<{ rows?: Row[]; accent?: string }>();

const rows = computed<Row[]>(() => {
    if (props.rows && props.rows.length) return props.rows;

    // INDULÓSABLON: fejléc, hangsúlyos hero, két oszlop, lábléc.
    return [
        { background: null, columns: 2, kinds: ['logo'] },
        { background: props.accent ?? null, columns: 1, kinds: ['heading', 'button'] },
        { background: null, columns: 2, kinds: ['image', 'text'] },
        { background: null, columns: 1, kinds: ['footer'] },
    ];
});
</script>

<template>
    <div class="vm-lib-thumb" aria-hidden="true">
        <div class="vm-lib-thumb__mail">
            <div
                v-for="(row, index) in rows"
                :key="index"
                class="vm-lib-thumb__row"
                :class="{ 'vm-lib-thumb__row--tall': row.kinds.some((kind) => ['image', 'heading', 'product', 'video'].includes(kind)) }"
                :style="row.background ? { background: row.background } : undefined"
            >
                <span v-for="column in row.columns" :key="column" :style="row.background ? { opacity: '0.35' } : undefined" />
            </div>
        </div>
    </div>
</template>
