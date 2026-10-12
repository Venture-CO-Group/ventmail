<script setup lang="ts">
import { VtButton, VtDrawer } from '@venture/ui';
import axios from 'axios';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TemplateData } from './types';

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

const props = defineProps<{ open: boolean; uuid: string; beforeRestore: () => Promise<boolean> }>();

const emit = defineEmits<{ 'update:open': [value: boolean]; restored: [template: TemplateData] }>();

const { t, locale } = useI18n();
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
            <p v-if="error" class="vm-banner vm-banner--danger" role="status">{{ error }}</p>
            <ol class="vm-ed-versions">
                <li v-for="(version, index) in versions" :key="version.number" :data-testid="`version-${version.number}`">
                    <strong>
                        v{{ version.number }}
                        <span v-if="index === 0" class="vm-ed-badge">{{ t('editor.versions.current') }}</span>
                    </strong>
                    <small>{{ version.createdAt ? new Date(version.createdAt).toLocaleString(locale) : '' }} · {{ version.author ?? t('editor.versions.system') }}</small>
                    <small>{{ t(`editor.versions.reasons.${version.reason}`, { from: version.restoredFrom ?? '' }) }}</small>
                    <VtButton v-if="index > 0" size="sm" variant="ghost" :loading="busy === version.number" :data-testid="`restore-${version.number}`" @click="restore(version.number)">
                        {{ t('editor.versions.restore') }}
                    </VtButton>
                </li>
            </ol>
            <p class="vm-ed-hint">{{ t('editor.versions.hint') }}</p>
        </div>
    </VtDrawer>
</template>
