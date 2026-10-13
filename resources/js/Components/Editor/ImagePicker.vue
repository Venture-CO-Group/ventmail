<script setup lang="ts">
import { VtButton, VtField, VtInput } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import { displayUrl } from './render';
import type { AssetDef, EditorCatalog } from './types';

/*
 | KÉPVÁLASZTÓ: URL, feltöltés (az `AssetStore` mögé — ma a `public` diszk, később a
 | ventVault, D10) vagy a workspace korábbi képei. Az ellenőrzés (MIME, méret, képméret) a
 | SZERVERÉ; a kliens csak a hibát mutatja.
 |
 | JELENTÉS: hiányzó @venture/ui komponens: fájlfeltöltő mező (`VtFileInput`).
 */
const props = defineProps<{ modelValue: string; label: string; assets: AssetDef[]; catalog: EditorCatalog; testid?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: string]; uploaded: [asset: AssetDef] }>();

const { t } = useI18n();
const input = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);
const showLibrary = ref(false);

async function upload(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) return;

    const form = new FormData();
    form.append('file', file);
    uploading.value = true;
    error.value = null;

    try {
        const response = await axios.post<AssetDef>('/template-assets', form, { headers: { Accept: 'application/json' } });
        emit('uploaded', response.data);
        emit('update:modelValue', response.data.url);
    } catch (caught) {
        const failure = caught as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
        error.value = Object.values(failure.response?.data?.errors ?? {}).flat()[0] ?? failure.response?.data?.message ?? failure.message;
    } finally {
        uploading.value = false;

        if (input.value) input.value.value = '';
    }
}
</script>

<template>
    <div class="vm-stack" :data-testid="testid">
        <VtField :label="label" :error="error ?? undefined">
            <VtInput :model-value="props.modelValue" :data-testid="testid ? `${testid}-url` : undefined" @update:model-value="emit('update:modelValue', String($event))" />
        </VtField>
        <div class="vm-cluster">
            <input ref="input" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden :data-testid="testid ? `${testid}-file` : undefined" @change="upload" />
            <VtButton size="sm" variant="ghost" :loading="uploading" @click="input?.click()"><VmIcon name="upload" /> {{ t('editor.image.upload') }}</VtButton>
            <VtButton v-if="assets.length" size="sm" variant="ghost" @click="showLibrary = !showLibrary">{{ t('editor.image.library') }}</VtButton>
        </div>
        <div v-if="showLibrary" class="vm-ed-assets">
            <button v-for="asset in assets" :key="asset.uuid" type="button" class="vm-ed-asset" :title="asset.name" @click="emit('update:modelValue', asset.url)">
                <img :src="displayUrl(asset.url, catalog)" :alt="asset.name" />
            </button>
        </div>
    </div>
</template>
