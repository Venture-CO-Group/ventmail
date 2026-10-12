<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtTabs, VtTextarea, type VtTab } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmDropzone from '../Form/VmDropzone.vue';
import VmForm from '../Form/VmForm.vue';
import VmFormActions from '../Form/VmFormActions.vue';
import VmFormRow from '../Form/VmFormRow.vue';
import VmFormSection from '../Form/VmFormSection.vue';
import type { ImportErrors, ImportState } from './types';

/**
 * 1. LÉPÉS — feltöltés vagy bevitel: kézi egyedi, beillesztett lista, CSV, XLSX.
 *
 * Mind a négy út egy `contact_imports` köteget hoz létre (a kézi és a beillesztett bevitel a
 * szerveren egy kis CSV-vé alakul), így a további lépések egyformák.
 */
const props = defineProps<{
    state: ImportState | null;
    errors: ImportErrors;
    maxMegabytes: number;
    maxRows: number;
}>();

const emit = defineEmits<{ (e: 'created'): void; (e: 'next'): void; (e: 'errors', value: ImportErrors): void }>();

const { t, n } = useI18n({ useScope: 'global' });

const tab = ref<string>(props.state?.sourceType === 'xlsx' ? 'xlsx' : props.state?.sourceType === 'csv' ? 'csv' : 'manual');
const busy = ref(false);

const manual = ref({ email: '', first_name: '', last_name: '' });
const pasted = ref('');

const tabs = computed<VtTab[]>(() => [
    { id: 'manual', label: t('audience.import.tab.manual') },
    { id: 'paste', label: t('audience.import.tab.paste') },
    { id: 'csv', label: t('audience.import.tab.csv') },
    { id: 'xlsx', label: t('audience.import.tab.xlsx') },
]);

const profile = computed(() => props.state?.profile ?? null);

const delimiterLabel = computed(() => {
    const map: Record<string, string> = { ',': 'comma', ';': 'semicolon', '\t': 'tab', '|': 'pipe' };

    return t(`audience.import.delimiter.${map[profile.value?.delimiter ?? ','] ?? 'comma'}`);
});

function submit(payload: Record<string, string | File>, forceFormData = false): void {
    busy.value = true;

    router.post('/audience/import', payload, {
        forceFormData,
        preserveScroll: true,
        onSuccess: () => {
            emit('errors', {});
            emit('created');
        },
        onError: (errors) => emit('errors', errors),
        onFinish: () => (busy.value = false),
    });
}

const clientError = ref<string | null>(null);

function uploadFile(file: File): void {
    // A szerver a végső ellenőrző; a böngésző előre szól, hogy ne egy fél perces feltöltés
    // után derüljön ki, hogy a fájl túl nagy.
    if (file.size > props.maxMegabytes * 1048576) {
        clientError.value = t('audience.import.tooLarge', { mb: props.maxMegabytes });

        return;
    }

    clientError.value = null;
    submit({ mode: 'file', file }, true);
}

function replace(): void {
    if (!props.state) return;

    router.post(`/audience/import/${props.state.uuid}/cancel`, {}, { onSuccess: () => emit('errors', {}) });
}

function sizeLabel(bytes: number | null): string {
    if (bytes === null) return '';

    return bytes >= 1048576 ? `${n(Math.round(bytes / 104857.6) / 10)} MB` : `${n(Math.max(1, Math.round(bytes / 102.4) / 10))} KB`;
}
</script>

<template>
    <section data-testid="import-step-upload">
        <h2 class="vm-wrap">{{ t('audience.import.step1.title') }}</h2>
        <p class="vt-text-muted">{{ t('audience.import.step1.subtitle') }}</p>

        <template v-if="state">
            <div class="vm-import-file" data-testid="import-file-card">
                <strong class="vm-wrap">{{ state.fileName ?? t(`audience.import.source.${state.sourceType}`) }}</strong>
                <span class="vt-text-muted">
                    {{ sizeLabel(state.fileSize) }} · {{ t('audience.import.rowsCount', { count: n(state.profile.rows) }) }} ·
                    {{ t('audience.import.uploaded') }}
                </span>
                <VtButton variant="ghost" data-testid="import-replace" @click="replace">{{ t('audience.import.replace') }}</VtButton>
            </div>

            <dl v-if="profile" class="vm-import-facts">
                <div>
                    <dt>{{ t('audience.import.fact.delimiter') }}</dt>
                    <dd>{{ delimiterLabel }}</dd>
                </div>
                <div>
                    <dt>{{ t('audience.import.fact.encoding') }}</dt>
                    <dd>{{ profile.encoding === 'Windows-1250' ? 'Windows-1250 → UTF-8' : 'UTF-8' }}</dd>
                </div>
                <div>
                    <dt>{{ t('audience.import.fact.header') }}</dt>
                    <dd>{{ profile.hasHeader ? t('audience.import.fact.headerYes') : t('audience.import.fact.headerNo') }}</dd>
                </div>
                <div>
                    <dt>{{ t('audience.import.fact.processing') }}</dt>
                    <dd>{{ t('audience.import.fact.streamed', { size: n(state.chunkSize) }) }}</dd>
                </div>
            </dl>

            <VmFormActions>
                <VtButton data-testid="import-next" @click="emit('next')">{{ t('audience.import.next') }}</VtButton>
            </VmFormActions>
        </template>

        <template v-else>
            <VtTabs v-model="tab" :tabs="tabs" :label="t('audience.import.step1.title')">
                <template #manual>
                    <VmForm @submit.prevent="submit({ mode: 'manual', ...manual })">
                        <VmFormSection>
                            <VtField :label="t('audience.import.manual.email')" :error="errors.email" required>
                                <VtInput v-model="manual.email" type="email" data-testid="import-email" />
                            </VtField>
                            <VmFormRow>
                                <VtField :label="t('audience.import.manual.firstName')">
                                    <VtInput v-model="manual.first_name" data-testid="import-first-name" />
                                </VtField>
                                <VtField :label="t('audience.import.manual.lastName')">
                                    <VtInput v-model="manual.last_name" data-testid="import-last-name" />
                                </VtField>
                            </VmFormRow>
                        </VmFormSection>
                        <VmFormActions>
                            <VtButton type="submit" :disabled="busy || !manual.email.trim()" data-testid="import-next">
                                {{ t('audience.import.next') }}
                            </VtButton>
                        </VmFormActions>
                    </VmForm>
                </template>

                <template #paste>
                    <VmForm @submit.prevent="submit({ mode: 'paste', emails: pasted })">
                        <VmFormSection>
                            <VtField :label="t('audience.import.paste.label')" :hint="t('audience.import.paste.hint')" :error="errors.emails" required>
                                <VtTextarea v-model="pasted" :rows="8" data-testid="import-paste" />
                            </VtField>
                        </VmFormSection>
                        <VmFormActions>
                            <VtButton type="submit" :disabled="busy || !pasted.trim()" data-testid="import-next">
                                {{ t('audience.import.next') }}
                            </VtButton>
                        </VmFormActions>
                    </VmForm>
                </template>

                <template #csv>
                    <VmDropzone
                        :title="t('audience.import.drop.title')"
                        :hint="t('audience.import.drop.hint', { rows: n(maxRows), mb: maxMegabytes })"
                        accept=".csv,.txt,text/csv,text/plain"
                        :input-label="t('audience.import.tab.csv')"
                        @select="uploadFile"
                    />
                    <p v-if="errors.file || clientError" class="vm-banner vm-banner--danger" role="alert" data-testid="import-file-error">{{ errors.file ?? clientError }}</p>
                </template>

                <template #xlsx>
                    <VmDropzone
                        :title="t('audience.import.drop.title')"
                        :hint="t('audience.import.drop.hint', { rows: n(maxRows), mb: maxMegabytes })"
                        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        :input-label="t('audience.import.tab.xlsx')"
                        @select="uploadFile"
                    />
                    <p v-if="errors.file || clientError" class="vm-banner vm-banner--danger" role="alert" data-testid="import-file-error">{{ errors.file ?? clientError }}</p>
                </template>
            </VtTabs>
        </template>
    </section>
</template>

<style scoped>
section {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

h2,
p {
    margin: 0;
}

.vm-import-file {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    padding: var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-glass-thin-fill);
}

.vm-import-file > :last-child {
    margin-inline-start: auto;
}

.vm-import-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-24) * 2)), 1fr));
    gap: var(--vt-space-4);
    margin: 0;
}

.vm-import-facts dt {
    font-size: var(--vt-fs-caption);
    color: var(--vt-text-muted);
}

.vm-import-facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.vt-text-muted {
    color: var(--vt-text-muted);
}
</style>
