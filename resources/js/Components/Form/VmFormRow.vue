<script setup lang="ts">
/**
 * MEZŐ-SOR: több mező egymás mellett, keskeny helyen egymás alatt.
 *
 * Media query nélkül (nincs töréspont-token): `auto-fit` + `minmax` rács, tehát a mezők
 * akkor törnek új sorba, amikor a minimum-szélességük már nem fér el. A minimum a
 * spacing-skálából számolt érték.
 */
</script>

<template>
    <div class="vm-form-row"><slot /></div>
</template>

<style scoped>
.vm-form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(calc(var(--vt-space-12) * 2) * 2)), 1fr));
    gap: var(--vt-space-5) var(--vt-space-6);
    align-items: start;
    min-width: 0;
}

.vm-form-row :deep(.vt-field) {
    gap: var(--vt-space-2);
    min-width: 0;
}
</style>
