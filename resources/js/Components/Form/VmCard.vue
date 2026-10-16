<script setup lang="ts">
import { VtCard } from '@venture-co-group/ui';

/**
 * KÁRTYA — a közös `VtCard` (Figma Card, Unified UI v2) adaptere: a fejléc (`title`, `#actions`,
 * `#badge`) és a törzs ritmusa a könyvtáré; itt csak a törzs elemeinek böngésző-margója nullázódik
 * (a cím, a bekezdés és a lista ne hozzon saját külső margót — a ritmust a törzs `gap`-je adja).
 */
withDefaults(
    defineProps<{
        title?: string;
        headingLevel?: 2 | 3 | 4 | 5 | 6;
        /** A törzs margó nélkül — tábla vagy lista a kártya széléig. */
        flush?: boolean;
    }>(),
    { title: undefined, headingLevel: 2, flush: false },
);
</script>

<template>
    <VtCard class="vm-card" :title="title" :heading-level="headingLevel" :flush="flush">
        <template v-if="$slots.header" #header><slot name="header" /></template>
        <template v-if="$slots.badge" #badge><slot name="badge" /></template>
        <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        <slot />
        <template v-if="$slots.footer" #footer><slot name="footer" /></template>
    </VtCard>
</template>

<style scoped>
.vm-card :deep(.vt-card__body) > :where(h1, h2, h3, h4, p, ol, ul, dl) {
    margin: 0;
    overflow-wrap: anywhere;
}
</style>
