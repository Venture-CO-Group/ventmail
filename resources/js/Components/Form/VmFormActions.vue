<script setup lang="ts">
/** GOMBSOR az űrlap alján: törik, ha szűk, és a gombok között `--vt-space-3` van. */
</script>

<template>
    <div class="vm-form-actions"><slot /></div>
</template>

<style scoped>
.vm-form-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}
</style>
