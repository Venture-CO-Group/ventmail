<script setup lang="ts">
import { VtBanner, VtButton, VtDrawer } from '@venture-co-group/ui';
import axios from 'axios';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TemplateData } from './types';
import { useFormat } from '../../composables/useFormat';

/*
 | VERZIÓTÖRTÉNET. A visszaállítás ÚJ verziót ír (a régiek megmaradnak), tehát maga a
 | visszaállítás is visszacsinálható. A visszaállítás előtt a szerkesztő ment, hogy a
 | jelenlegi állapot is a történetben legyen.
 */
interface VersionRow {
    number: number;
    reason: string;
    restoredFrom: number | null;
    author: string | null;
    createdAt: string | null;
    blockCount: number;
}

/** `readOnly` (review r5 N5): a zárolt sablon verziói megnézhetők, de nem állíthatók vissza (a szerver úgyis 403-at adna). */
const props = defineProps<{ open: boolean; uuid: string; beforeRestore: () => Promise<boolean>; readOnly?: boolean }>();

const emit = defineEmits<{ 'update:open': [value: boolean]; restored: [template: TemplateData] }>();

const { t } = useI18n();
const format = useFormat();
const versions = ref<VersionRow[]>([]);
const busy = ref<number | null>(null);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    const response = await axios.get<{ versions: VersionRow[] }>(`/templates/${props.uuid}/versions`);
    versions.value = response.data.versions;
}

watch(
    () => props.open,
    (open) => {
        if (open) void load();
    },
);

async function restore(number: number): Promise<void> {
    busy.value = number;
    error.value = null;

    try {
        await props.beforeRestore();
        const response = await axios.post<{ template: TemplateData; version: number }>(`/templates/${props.uuid}/versions/${number}/restore`);
        emit('restored', response.data.template);
        await load();
    } catch (caught) {
        const data = (caught as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
        error.value = Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? t('editor.versions.failed');
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <VtDrawer :open="open" :title="t('editor.versions.title')" side="right" :close-label="t('editor.common.close')" @update:open="emit('update:open', $event)" @close="emit('update:open', false)">
        <div class="vm-stack" data-testid="versions-drawer">
            <VtBanner v-if="error" tone="danger" role="status">{{ error }}</VtBanner>
            <ol class="vm-ed-versions">
                <li v-for="(version, index) in versions" :key="version.number" :data-testid="`version-${version.number}`">
                    <strong>
                        v{{ version.number }}
                        <span v-if="index === 0" class="vm-ed-badge">{{ t('editor.versions.current') }}</span>
                    </strong>
                    <small>{{ version.createdAt ? format.dateTime(version.createdAt) : '' }} · {{ version.author ?? t('editor.versions.system') }}</small>
                    <small>{{ t(`editor.versions.reasons.${version.reason}`, { from: version.restoredFrom ?? '' }) }}</small>
                    <VtButton v-if="index > 0 && !readOnly" size="sm" variant="ghost" :loading="busy === version.number" :data-testid="`restore-${version.number}`" @click="restore(version.number)">
                        {{ t('editor.versions.restore') }}
                    </VtButton>
                </li>
            </ol>
            <p class="vm-ed-hint">{{ t('editor.versions.hint') }}</p>
        </div>
    </VtDrawer>
</template>
