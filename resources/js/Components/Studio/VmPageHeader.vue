<script setup lang="ts">
/**
 * OLDALFEJ (v1.1 · 5. modul): felső-címke · cím · bevezető, jobbra a műveletek.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: oldalfej (page header) — a shell modul (1.) már
 * jelezte; ez a Studio-oldalak közös, tokenes változata.
 */
defineProps<{
    eyebrow?: string;
    title: string;
    lead?: string;
    testid?: string;
}>();
</script>

<template>
    <header class="vm-page-head" :data-testid="testid">
        <div class="vm-page-head__text">
            <p v-if="eyebrow" class="vm-page-head__eyebrow">{{ eyebrow }}</p>
            <h1 class="vt-text-display vm-wrap">{{ title }}</h1>
            <p v-if="lead" class="vm-page-head__lead">{{ lead }}</p>
        </div>
        <div v-if="$slots.default" class="vm-page-head__actions">
            <slot />
        </div>
    </header>
</template>

<style scoped>
.vm-page-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-page-head__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-page-head__text h1 {
    margin: 0;
}

.vm-page-head__eyebrow {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
    font-weight: var(--vt-weight-semibold);
    letter-spacing: var(--vt-tracking-wider);
    text-transform: uppercase;
}

.vm-page-head__lead {
    margin: 0;
    color: var(--vt-text-muted);
}

.vm-page-head__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}
</style>
