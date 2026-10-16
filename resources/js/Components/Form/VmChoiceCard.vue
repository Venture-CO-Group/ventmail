<script setup lang="ts">
import { VtRadio } from '@venture-co-group/ui';

/**
 * VÁLASZTÓ-KÁRTYA: rádiógomb címmel és leírással, a teljes kártya kattintható.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: választó-kártya (radio card). A lokális változat
 * a `VtRadio`-ra épül, a kerete tokenekből jön.
 */
defineProps<{
    modelValue: string | number | undefined;
    value: string | number;
    name: string;
    title: string;
    description?: string;
}>();

defineEmits<{ (e: 'update:modelValue', value: string | number): void }>();
</script>

<template>
    <div class="vm-choice" :class="{ 'is-selected': modelValue === value }">
        <VtRadio
            :model-value="modelValue"
            :value="value"
            :name="name"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <span class="vm-choice__title">{{ title }}</span>
        </VtRadio>
        <p v-if="description" class="vm-choice__description">{{ description }}</p>
    </div>
</template>

<style scoped>
.vm-choice {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    padding: var(--vt-space-3) var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-surface-2);
    min-width: 0;
}

.vm-choice.is-selected {
    border-color: var(--vt-accent);
}

.vm-choice__title {
    font-weight: var(--vt-weight-medium);
}

.vm-choice__description {
    margin: 0;
    font-size: var(--vt-type-caption-size);
    color: var(--vt-text-secondary);
    overflow-wrap: anywhere;
}
</style>
