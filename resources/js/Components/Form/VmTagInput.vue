<script setup lang="ts">
import { VtBadge, VtInput } from '@venture/ui';
import { ref } from 'vue';

/**
 * CÍMKE-BEVITEL: Enterre vagy vesszőre új címkét fűz, a címkére kattintva törli.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: tag input (chip-es többértékű szövegmező).
 */
const props = defineProps<{
    modelValue: string[];
    placeholder?: string;
    removeLabel: string;
    max?: number;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>();

const draft = ref('');

function commit(): void {
    const value = draft.value.trim().replace(/,+$/, '').trim();

    draft.value = '';

    if (value === '' || props.modelValue.includes(value) || props.modelValue.length >= (props.max ?? 20)) {
        return;
    }

    emit('update:modelValue', [...props.modelValue, value]);
}

function remove(tag: string): void {
    emit('update:modelValue', props.modelValue.filter((candidate) => candidate !== tag));
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        commit();
    }
}
</script>

<template>
    <div class="vm-tags">
        <div v-if="modelValue.length" class="vm-tags__list">
            <button
                v-for="tag in modelValue"
                :key="tag"
                type="button"
                class="vm-tags__chip"
                :aria-label="`${removeLabel}: ${tag}`"
                :data-testid="`tag-${tag}`"
                @click="remove(tag)"
            >
                <VtBadge tone="accent">{{ tag }} ×</VtBadge>
            </button>
        </div>
        <div @keydown="onKeydown" @focusout="commit">
            <VtInput v-model="draft" :placeholder="placeholder" data-testid="tag-input" />
        </div>
    </div>
</template>

<style scoped>
.vm-tags {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-tags__list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-2);
}

.vm-tags__chip {
    padding: 0;
    border: 0;
    background: none;
    cursor: pointer;
    color: inherit;
}
</style>
