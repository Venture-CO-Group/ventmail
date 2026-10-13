<script setup lang="ts">
/*
 | KAPCSOLÓ (igaz/hamis) — natív jelölőnégyzet, címkével.
 |
 | JELENTÉS: hiányzó @venture/ui komponens: kapcsoló (`VtSwitch`). A `VtCheckbox` itt is
 | használható lenne, de a kártya-sor elrendezést (felirat + leírás + kapcsoló) nem adja.
 */
defineProps<{ modelValue: boolean; label: string; hint?: string; testid?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <label class="vm-ed-switch" :data-testid="testid">
        <span class="vm-stack">
            <strong>{{ label }}</strong>
            <small v-if="hint" class="vm-ed-hint">{{ hint }}</small>
        </span>
        <input type="checkbox" role="switch" :checked="modelValue" @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)" />
    </label>
</template>
