<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBadge, VtButton, VtField, VtInput, VtSelect, type VtSelectOption } from '@venture/ui';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmChoiceCard from '../Form/VmChoiceCard.vue';
import VmFormActions from '../Form/VmFormActions.vue';
import VmFormRow from '../Form/VmFormRow.vue';
import VmTagInput from '../Form/VmTagInput.vue';
import type { ImportErrors, ImportList, ImportState } from './types';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * 3. LÉPÉS — beállítások: cél lista, címkék, a meglévők frissítése, és a KÖTELEZŐ jogalap.
 *
 * Az eredmény-státuszt a felület csak MEGMUTATJA (ugyanaz a szabály, mint a szerveren:
 * `subscribed` kizárólag igazolt hozzájárulás + forrás + dátum esetén); a döntést a szerver
 * hozza, és az import-rekordba írja.
 */
const props = defineProps<{ state: ImportState; lists: ImportList[]; errors: ImportErrors }>();

const emit = defineEmits<{ (e: 'saved'): void; (e: 'back'): void; (e: 'errors', value: ImportErrors): void }>();

const { t } = useI18n({ useScope: 'global' });
const code = useCodes();
const format = useFormat();
// A számok a PROFIL számformátuma szerint (D11), nem a vue-i18n locale-ja szerint.
const n = (value: number): string => format.number(value);

const initial = props.state.settings;

const form = reactive({
    listUuid: initial.listUuid ?? '',
    tags: [...initial.tags],
    updateMode: initial.updateMode,
    legalBasis: (initial.legalBasis ?? '') as string,
    consentSource: initial.consentSource ?? '',
    consentDate: initial.consentDate ?? '',
    rolePolicy: initial.rolePolicy,
    disposablePolicy: initial.disposablePolicy,
});

const busy = ref(false);

const listOptions = computed<VtSelectOption[]>(() => [
    { value: '', label: t('audience.import.noList') },
    ...props.lists.map((list) => ({ value: list.uuid, label: `${list.name} (${n(list.contacts)})` })),
]);

const policyOptions = computed<VtSelectOption[]>(() => [
    { value: 'skip', label: t('audience.import.policy.skip') },
    { value: 'import', label: t('audience.import.policy.import') },
]);

const needsEvidence = computed(() => form.legalBasis === 'verified_consent' || form.legalBasis === 'legitimate_interest');
const subscribed = computed(() => form.legalBasis === 'verified_consent' && form.consentSource.trim() !== '' && form.consentDate !== '');
const ready = computed(() => form.legalBasis !== '' && (!needsEvidence.value || (form.consentSource.trim() !== '' && form.consentDate !== '')));

function save(): void {
    busy.value = true;

    router.post(`/audience/import/${props.state.uuid}/settings`, {
        listUuid: form.listUuid || null,
        tags: form.tags,
        updateMode: form.updateMode,
        legalBasis: form.legalBasis || null,
        consentSource: form.consentSource || null,
        consentDate: form.consentDate || null,
        rolePolicy: form.rolePolicy,
        disposablePolicy: form.disposablePolicy,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            emit('errors', {});
            emit('saved');
        },
        onError: (errors) => emit('errors', errors),
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <section data-testid="import-step-settings">
        <h2 class="vm-wrap">{{ t('audience.import.step3.title') }}</h2>
        <p class="vt-text-muted">{{ t('audience.import.step3.subtitle') }}</p>

        <VmFormRow>
            <VtField :label="t('audience.import.targetList')" :error="errors.listUuid">
                <VtSelect v-model="form.listUuid" :options="listOptions" data-testid="import-list" />
            </VtField>
            <VtField :label="t('audience.import.tags')" :hint="t('audience.import.tagsHint')" :error="errors.tags">
                <VmTagInput v-model="form.tags" :placeholder="t('audience.import.tagsPlaceholder')" :remove-label="t('audience.import.removeTag')" />
            </VtField>
        </VmFormRow>

        <fieldset class="vm-import-group">
            <legend>{{ t('audience.import.updateExisting') }}</legend>
            <div class="vm-import-choices">
                <VmChoiceCard
                    v-for="mode in (['skip', 'fill', 'overwrite'] as const)"
                    :key="mode"
                    v-model="form.updateMode"
                    :value="mode"
                    name="update-mode"
                    :title="t(`audience.import.update.${mode}.title`)"
                    :description="t(`audience.import.update.${mode}.description`)"
                    :data-testid="`update-mode-${mode}`"
                />
            </div>
            <p v-if="errors.updateMode" class="vm-banner vm-banner--danger" role="alert">{{ errors.updateMode }}</p>
        </fieldset>

        <fieldset class="vm-import-group">
            <legend>
                {{ t('audience.import.legal.heading') }}
                <VtBadge tone="danger" size="sm">{{ t('audience.import.required') }}</VtBadge>
            </legend>
            <div class="vm-import-choices">
                <VmChoiceCard
                    v-for="basis in (['verified_consent', 'legitimate_interest', 'unknown'] as const)"
                    :key="basis"
                    v-model="form.legalBasis"
                    :value="basis"
                    name="legal-basis"
                    :title="t(`audience.import.legal.${basis}.title`)"
                    :description="t(`audience.import.legal.${basis}.description`)"
                    :data-testid="`legal-${basis}`"
                />
            </div>
            <p v-if="errors.legalBasis" class="vm-banner vm-banner--danger" role="alert" data-testid="legal-error">{{ errors.legalBasis }}</p>

            <VmFormRow>
                <VtField
                    :label="t('audience.import.legal.source')"
                    :hint="t('audience.import.legal.sourceHint')"
                    :error="errors.consentSource"
                    :required="needsEvidence"
                >
                    <VtInput v-model="form.consentSource" :placeholder="t('audience.import.legal.sourcePlaceholder')" data-testid="consent-source" />
                </VtField>
                <VtField :label="t('audience.import.legal.date')" :error="errors.consentDate" :required="needsEvidence">
                    <VtInput v-model="form.consentDate" type="date" data-testid="consent-date" />
                </VtField>
            </VmFormRow>
        </fieldset>

        <div class="vm-import-status" :class="{ 'is-subscribed': subscribed }" role="status" data-testid="import-status-box">
            <strong>
                {{ t('audience.import.legal.resultingStatus') }}
                <!-- D292: a gépi kód a `data-status`-ban, a jelvény a fordított címkét mutatja. -->
                <span :data-status="subscribed ? 'subscribed' : 'pending'"><VtBadge :tone="subscribed ? 'success' : 'warning'">{{ code('contactStatus', subscribed ? 'subscribed' : 'pending') }}</VtBadge></span>
            </strong>
            <span class="vt-text-muted">
                {{ subscribed ? t('audience.import.legal.statusSubscribed') : t('audience.import.legal.statusPending') }}
            </span>
        </div>

        <VmFormRow>
            <VtField :label="t('audience.import.rolePolicy')" :hint="t('audience.import.rolePolicyHint')">
                <VtSelect v-model="form.rolePolicy" :options="policyOptions" data-testid="role-policy" />
            </VtField>
            <VtField :label="t('audience.import.disposablePolicy')" :hint="t('audience.import.disposablePolicyHint')">
                <VtSelect v-model="form.disposablePolicy" :options="policyOptions" data-testid="disposable-policy" />
            </VtField>
        </VmFormRow>

        <p class="vm-banner vm-banner--warning" role="note">{{ t('audience.import.suppressionNotice') }}</p>
        <p class="vt-text-muted">{{ t('audience.import.noMail') }}</p>

        <VmFormActions>
            <VtButton variant="glass" data-testid="import-back" @click="emit('back')">{{ t('audience.import.back') }}</VtButton>
            <VtButton :disabled="busy || !ready" data-testid="import-next" @click="save">{{ t('audience.import.makePreview') }}</VtButton>
        </VmFormActions>
    </section>
</template>

<style scoped>
section {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-5);
    min-width: 0;
}

h2,
p {
    margin: 0;
}

.vt-text-muted {
    color: var(--vt-text-muted);
}

.vm-import-group {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.vm-import-group legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2);
    padding: 0;
    margin-block-end: var(--vt-space-3);
    font-family: var(--vt-font-display);
    font-weight: var(--vt-weight-semibold);
}

.vm-import-choices {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-24) * 2)), 1fr));
    gap: var(--vt-space-3);
}

.vm-import-status {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    padding: var(--vt-space-4);
    border: thin solid var(--vt-color-warning);
    border-radius: var(--vt-radius-md);
}

.vm-import-status.is-subscribed {
    border-color: var(--vt-color-success);
}
</style>
