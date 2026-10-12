<script setup lang="ts">
/**
 * ŰRLAP-SZAKASZ: opcionális cím + leírás, alatta a mezők `--vt-space-5` közzel.
 *
 * A szakasz a címke↔mező távolságot is kikényszeríti (`--vt-space-2`), hogy egy oldal ne
 * tudja véletlenül felülírni: a `VtField` alapértéke ugyanez, de itt rögzítve van.
 */
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <section class="vm-form-section">
        <header v-if="title || description" class="vm-form-section__head">
            <h3 v-if="title" class="vm-form-section__title">{{ title }}</h3>
            <p v-if="description" class="vm-form-section__description">{{ description }}</p>
        </header>

        <div class="vm-form-section__body"><slot /></div>
    </section>
</template>

<style scoped>
.vm-form-section {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

.vm-form-section__head {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
}

.vm-form-section__title {
    margin: 0;
    font-family: var(--vt-font-display);
    font-size: var(--vt-fs-h6);
    font-weight: var(--vt-weight-semibold);
    overflow-wrap: anywhere;
}

.vm-form-section__description {
    margin: 0;
    font-size: var(--vt-fs-body-sm);
    color: var(--vt-text-muted);
}

.vm-form-section__body {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-5);
    min-width: 0;
}

.vm-form-section__body :deep(.vt-field) {
    gap: var(--vt-space-2);
    min-width: 0;
}
</style>
