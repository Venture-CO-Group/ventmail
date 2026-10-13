<script setup lang="ts">
import { VtButton, VtField, VtInput, VtModal } from '@venture/ui';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import VmForm from '../Form/VmForm.vue';
import VmFormActions from '../Form/VmFormActions.vue';

/*
 | NÉV-BEKÉRŐ (Mentés másként, Mentés sablonként, Mentés blokként). A szerver hibáját
 | (foglalt név, hibás blokk) a mező alatt mutatja.
 */
const props = defineProps<{ open: boolean; title: string; initial: string; submitLabel: string; error?: string | null; busy?: boolean }>();

const emit = defineEmits<{ 'update:open': [value: boolean]; submit: [name: string] }>();

const { t } = useI18n();
const name = ref(props.initial);

watch(
    () => props.open,
    (open) => {
        if (open) name.value = props.initial;
    },
);
</script>

<template>
    <VtModal :open="open" :title="title" @update:open="emit('update:open', $event)" @close="emit('update:open', false)">
        <VmForm @submit.prevent="name.trim() && emit('submit', name.trim())">
            <VtField :label="t('editor.common.name')" :error="error ?? undefined">
                <VtInput v-model="name" data-testid="name-modal-input" />
            </VtField>
            <VmFormActions>
                <VtButton variant="ghost" @click="emit('update:open', false)">{{ t('editor.common.cancel') }}</VtButton>
                <VtButton type="submit" :loading="busy" :disabled="!name.trim()" data-testid="name-modal-submit">{{ submitLabel }}</VtButton>
            </VmFormActions>
        </VmForm>
    </VtModal>
</template>
