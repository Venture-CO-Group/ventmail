<script setup lang="ts">
import { VtBanner, VtModal } from '@venture-co-group/ui';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import EdSegment from './EdSegment.vue';

/*
 | ELŐNÉZET — a SZERVER fordítójának kimenete (MJML → HTML + plaintext), a mentetlen
 | állapotból. Amit itt látsz, az megy ki.
 |
 | A levél HTML-je `sandbox`-olt iframe-ben (`srcdoc`, szkript nélkül) jelenik meg: a
 | nyers HTML blokk tartalma így sem a mi DOM-unkba, sem a mi originünkre nem jut.
 */
const props = defineProps<{ open: boolean; uuid: string; payload: () => Record<string, unknown> }>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { t } = useI18n();
const mode = ref<'desktop' | 'mobile' | 'dark' | 'text'>('desktop');
const html = ref('');
const text = ref('');
const bytes = ref(0);
const error = ref<string | null>(null);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;

        error.value = null;

        try {
            const response = await axios.post(`/templates/${props.uuid}/preview`, props.payload());
            html.value = response.data.html;
            text.value = response.data.text;
            bytes.value = response.data.bytes;
        } catch (caught) {
            const data = (caught as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
            error.value = Object.values(data?.errors ?? {}).flat().join(' ') || data?.message || t('editor.preview.failed');
        }
    },
);

const options = computed(() => [
    { value: 'desktop', label: t('editor.device.desktop'), icon: 'desktop' },
    { value: 'mobile', label: t('editor.device.mobile'), icon: 'mobile' },
    { value: 'dark', label: t('editor.device.dark'), icon: 'moon' },
    { value: 'text', label: t('editor.preview.plain'), icon: 'file-text' },
]);
</script>

<template>
    <VtModal :open="open" :title="t('editor.preview.title')" @update:open="emit('update:open', $event)" @close="emit('update:open', false)">
        <div class="vm-ed-preview" :class="{ 'vm-ed-preview--mobile': mode === 'mobile', 'vm-ed-preview--dark': mode === 'dark' }" data-testid="preview-modal">
            <EdSegment v-model="mode" :options="options" :label="t('editor.preview.title')" testid="preview-mode" />
            <VtBanner v-if="error" tone="danger" role="status" data-testid="preview-error">{{ error }}</VtBanner>
            <template v-else>
                <pre v-if="mode === 'text'" class="vm-ed-plain" data-testid="preview-text">{{ text }}</pre>
                <iframe v-else :title="t('editor.preview.title')" sandbox="" :srcdoc="html" data-testid="preview-frame" />
                <small class="vm-ed-hint">{{ t('editor.preview.size', { size: Math.round(bytes / 1024) }) }}</small>
            </template>
        </div>
    </VtModal>
</template>
