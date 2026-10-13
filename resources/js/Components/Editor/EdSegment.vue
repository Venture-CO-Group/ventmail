<script setup lang="ts">
import VmIcon from '../Shell/VmIcon.vue';

/*
 | SZEGMENTÁLT VÁLASZTÓ ikonokkal (eszköz-nézet, igazítás). A `VtSegmented` szöveges
 | opciókat ad; az ikonos változat hiányzik.
 |
 | JELENTÉS: hiányzó @venture/ui komponens: ikonos szegmentált választó.
 */
defineProps<{ modelValue: string; options: Array<{ value: string; label: string; icon?: string }>; label: string; iconOnly?: boolean; testid?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div class="vm-ed-segment" role="group" :aria-label="label" :data-testid="testid">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :aria-pressed="modelValue === option.value"
            :aria-label="iconOnly ? option.label : undefined"
            :title="option.label"
            :data-testid="testid ? `${testid}-${option.value}` : undefined"
            @click="emit('update:modelValue', option.value)"
        >
            <VmIcon v-if="option.icon" :name="option.icon" />
            <span v-if="!iconOnly">{{ option.label }}</span>
        </button>
    </div>
</template>
