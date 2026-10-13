<script setup lang="ts">
/**
 * KÉT PANELES ELRENDEZÉS (v1.1 · 7. modul): oldalsáv + fő panel, ami keskeny képernyőn
 * magától egymás alá törik — media query (és beégetett töréspont) nélkül, a „sidebar"
 * flex-mintával. A szélességek token-többszörösök.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: split / sidebar layout-primitív.
 */
withDefaults(defineProps<{ reverse?: boolean; wide?: boolean }>(), { reverse: false, wide: false });
</script>

<template>
    <div class="vm-split" :class="{ 'vm-split--reverse': reverse, 'vm-split--wide': wide }">
        <div class="vm-split__side"><slot name="side" /></div>
        <div class="vm-split__main"><slot /></div>
    </div>
</template>

<style scoped>
.vm-split {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-split--reverse {
    flex-direction: row-reverse;
}

.vm-split__side {
    flex: 1 1 calc(var(--vt-space-32) * 2.5);
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

.vm-split--wide .vm-split__side {
    flex-basis: calc(var(--vt-space-32) * 3);
}

.vm-split__main {
    flex: 999 1 calc(var(--vt-space-32) * 4);
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}
</style>
