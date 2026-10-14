<script setup lang="ts">
import { VtButton, VtField, VtFileUpload, VtInput } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { uploadLabels } from '../../composables/uploadLabels';
import { displayUrl } from './render';
import type { AssetDef, EditorCatalog } from './types';

/*
 | KÉPVÁLASZTÓ: URL, feltöltés (az `AssetStore` mögé — ma a `public` diszk, később a
 | ventVault, D10) vagy a workspace korábbi képei. Az ellenőrzés (MIME, méret, képméret) a
 | SZERVERÉ; a kliens csak a hibát mutatja.
 |
 | A feltöltő a @venture/ui `VtFileUpload` (0.3.0, D317): típus és méret (a szerver
 | `template.assets.max_bytes`-a, a katalógusból) a kiválasztáskor; siker után `null`.
 */
const props = defineProps<{ modelValue: string; label: string; assets: AssetDef[]; catalog: EditorCatalog; testid?: string }>();

const emit = defineEmits<{ 'update:modelValue': [value: string]; uploaded: [asset: AssetDef] }>();

const { t } = useI18n();
const file = ref<File | null>(null);
const uploading = ref(false);
const labels = computed(() => uploadLabels(t, 'PNG, JPEG, GIF, WebP', props.catalog.assetMaxBytes || undefined));
const error = ref<string | null>(null);
const showLibrary = ref(false);

async function upload(chosen: File | null): Promise<void> {
    file.value = chosen;

    if (!chosen) return;

    const form = new FormData();
    form.append('file', chosen);
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
        file.value = null;
    }
}
</script>

<template>
    <div class="vm-stack" :data-testid="testid">
        <VtField :label="label" :error="error ?? undefined">
            <VtInput :model-value="props.modelValue" :data-testid="testid ? `${testid}-url` : undefined" @update:model-value="emit('update:modelValue', String($event))" />
        </VtField>
        <VtFileUpload
            :model-value="file"
            accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp"
            v-bind="labels"
            :button-label="t('editor.image.upload')"
            :disabled="uploading"
            :data-testid="testid ? `${testid}-upload` : undefined"
            @update:model-value="upload"
        />
        <div v-if="assets.length" class="vm-cluster">
            <VtButton size="sm" variant="ghost" @click="showLibrary = !showLibrary">{{ t('editor.image.library') }}</VtButton>
        </div>
        <div v-if="showLibrary" class="vm-ed-assets">
            <button v-for="asset in assets" :key="asset.uuid" type="button" class="vm-ed-asset" :title="asset.name" @click="emit('update:modelValue', asset.url)">
                <img :src="displayUrl(asset.url, catalog)" :alt="asset.name" />
            </button>
        </div>
    </div>
</template>
