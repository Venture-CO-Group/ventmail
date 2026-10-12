<script setup lang="ts">
/*
 | A teljes glow-keret fókuszkártyákhoz (belépő, megtagadva). Egyetlen megvalósítás él:
 | `.vm-glow-rim` a `glow.css`-ben; ez csak ráteszi — ugyanaz a komponens, mint a ventSSO-é.
 */
withDefaults(defineProps<{ busy?: boolean; tag?: string }>(), { busy: false, tag: 'div' });
</script>

<template>
    <component :is="tag" class="vm-glow-rim" :data-busy="busy ? 'true' : undefined">
        <div class="vm-glow-rim__card glow-rim__card">
            <slot />
        </div>
    </component>
</template>

<style scoped>
.glow-rim__card {
    padding: var(--vt-space-8);
    border: thin solid transparent;
}
</style>
