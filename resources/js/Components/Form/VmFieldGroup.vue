<script setup lang="ts">
/**
 * MEZŐ-CSOPORT (`fieldset` + `legend`): jelölőnégyzetek, rádiók halmaza.
 *
 * A `legend` ↔ tartalom köz `--vt-space-2`, ugyanaz, mint a címke ↔ mező — a felhasználó
 * a két mintát egyformának olvassa.
 */
defineProps<{
    legend: string;
    hint?: string;
}>();
</script>

<template>
    <fieldset class="vm-field-group">
        <legend class="vm-field-group__legend">{{ legend }}</legend>
        <p v-if="hint" class="vm-field-group__hint">{{ hint }}</p>
        <div class="vm-field-group__items"><slot /></div>
    </fieldset>
</template>

<style scoped>
.vm-field-group {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.vm-field-group__legend {
    padding: 0;
    margin-block-end: var(--vt-space-2);
    font-size: var(--vt-type-body-m-size);
    font-weight: var(--vt-weight-medium);
    color: var(--vt-text-primary);
}

.vm-field-group__hint {
    margin: 0;
    font-size: var(--vt-type-caption-size);
    color: var(--vt-text-secondary);
}

.vm-field-group__items {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-3) var(--vt-space-5);
    min-width: 0;
}
</style>
