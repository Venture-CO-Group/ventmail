<script setup lang="ts">
import { VtCard } from '@venture/ui';

/**
 * KÁRTYA — a `VtCard` burkolója, ami a BELSŐ RITMUST is adja.
 *
 * A `VtCard` a belső margót (`--vt-space-6`) adja, de a tartalom elemei között nincs
 * távolság: a fejléc, a bekezdés, a tábla és az űrlap egymásra ragadt. A ritmust ITT
 * oldjuk meg (egy flex-oszlop, `--vt-space-4` köz), nem oldalanként — így minden kártya
 * ugyanúgy lélegzik, és egy oldalnak nincs mit kézzel igazítania.
 *
 * JELENTÉS a @venture/ui felé: a `VtCard` törzse kapjon `gap`-et (vagy stack-primitívet);
 * ha megkapja, ez a burkoló kidobható.
 */
withDefaults(
    defineProps<{
        glass?: boolean;
        glow?: boolean;
    }>(),
    { glass: false, glow: false },
);
</script>

<template>
    <VtCard class="vm-card" :glass="glass" :glow="glow">
        <template v-if="$slots.header" #header><slot name="header" /></template>
        <div class="vm-card__content"><slot /></div>
        <template v-if="$slots.footer" #footer><slot name="footer" /></template>
    </VtCard>
</template>

<style scoped>
.vm-card {
    min-width: 0;
}

.vm-card__content {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

/* A kártya címei és bekezdései nem hordoznak saját külső margót — a ritmust a `gap` adja. */
.vm-card__content > :deep(h1),
.vm-card__content > :deep(h2),
.vm-card__content > :deep(h3),
.vm-card__content > :deep(p),
.vm-card__content > :deep(ol),
.vm-card__content > :deep(ul) {
    margin: 0;
    overflow-wrap: anywhere;
}
</style>
