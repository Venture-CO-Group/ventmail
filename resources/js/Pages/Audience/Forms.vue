<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtSelect, VtTextarea } from '@venture/ui';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * BEIRATKOZÓ ŰRLAPOK.
 *
 * A képernyő igazi terméke a BEÁGYAZHATÓ KÓD: az űrlap az ügyfél weboldalán él, mi csak a
 * végpontot adjuk. A honeypot mező neve űrlaponként generált, a time-trap időbélyege pedig
 * a beküldés része — ha ezek hiányoznak a beágyazott HTML-ből, a bot-védelem CSENDBEN nem
 * működik. Ezért ad ez a képernyő kész kódot, nem mezőnév-listát.
 */
interface FormRow {
    uuid: string;
    name: string;
    slug: string;
    active: boolean;
    listName: string | null;
    listUuid: string | null;
    honeypotField: string;
    minSeconds: number;
    policyVersion: string | null;
    consentText: string | null;
    redirectUrl: string | null;
    listContacts: number | null;
    snippet: string;
}

const props = defineProps<{
    forms: FormRow[];
    lists: Array<{ uuid: string; name: string }>;
    endpointBase: string;
}>();

const { t } = useI18n();

const blank = () => ({
    name: '',
    listUuid: '',
    minSeconds: '5',
    policyVersion: '',
    consentText: t('forms.consentDefault'),
    redirectUrl: '',
});

const form = ref(blank());
const editing = ref<string | null>(null);
const snippetFor = ref<string | null>(null);

const listOptions = computed(() => [
    { value: '', label: t('forms.noList') },
    ...props.lists.map((list) => ({ value: list.uuid, label: list.name })),
]);

function payload() {
    return {
        name: form.value.name.trim(),
        listUuid: form.value.listUuid || null,
        minSeconds: Number(form.value.minSeconds),
        policyVersion: form.value.policyVersion.trim() || null,
        consentText: form.value.consentText.trim(),
        redirectUrl: form.value.redirectUrl.trim() || null,
    };
}

function submit(): void {
    const url = editing.value ? `/forms/${editing.value}` : '/forms';

    router.post(url, payload(), {
        preserveScroll: true,
        onSuccess: () => {
            form.value = blank();
            editing.value = null;
        },
    });
}

function edit(row: FormRow): void {
    editing.value = row.uuid;
    form.value = {
        name: row.name,
        listUuid: row.listUuid ?? '',
        minSeconds: String(row.minSeconds),
        policyVersion: row.policyVersion ?? '',
        consentText: row.consentText ?? '',
        redirectUrl: row.redirectUrl ?? '',
    };
}

function toggle(row: FormRow): void {
    router.post(`/forms/${row.uuid}/toggle`, {}, { preserveScroll: true });
}

</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('forms.title') }}</h1>

        <p class="vm-banner" role="status">{{ t('forms.note') }}</p>

        <VmCard data-testid="form-editor">
            <h2>{{ editing ? t('forms.edit') : t('forms.create') }}</h2>

            <VmForm @submit.prevent="submit">
                <VmFormSection>
                    <VtField :label="t('forms.name')">
                        <VtInput v-model="form.name" data-testid="form-name" />
                    </VtField>

                    <VmFormRow>
                        <VtField :label="t('forms.list')" :hint="t('forms.listHint')">
                            <VtSelect v-model="form.listUuid" :options="listOptions" data-testid="form-list" />
                        </VtField>

                        <VtField :label="t('forms.minSeconds')" :hint="t('forms.minSecondsHint')">
                            <VtInput v-model="form.minSeconds" data-testid="form-min-seconds" />
                        </VtField>
                    </VmFormRow>

                    <VtField :label="t('forms.consentText')" :hint="t('forms.consentTextHint')">
                        <VtTextarea v-model="form.consentText" data-testid="form-consent-text" />
                    </VtField>

                    <VmFormRow>
                        <VtField :label="t('forms.policyVersion')" :hint="t('forms.policyVersionHint')">
                            <VtInput v-model="form.policyVersion" data-testid="form-policy-version" />
                        </VtField>

                        <VtField :label="t('forms.redirectUrl')" :hint="t('forms.redirectUrlHint')">
                            <VtInput v-model="form.redirectUrl" data-testid="form-redirect" placeholder="https://" />
                        </VtField>
                    </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton
                        type="submit"
                        :disabled="!form.name.trim() || !form.consentText.trim()"
                        data-testid="form-submit"
                    >
                        {{ editing ? t('forms.saveAction') : t('forms.createAction') }}
                    </VtButton>

                    <VtButton
                        v-if="editing"
                        variant="ghost"
                        data-testid="form-cancel"
                        @click="
                            editing = null;
                            form = blank();
                        "
                    >
                        {{ t('forms.cancel') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <p v-if="!forms.length" data-testid="forms-empty">{{ t('forms.empty') }}</p>

            <div v-for="row in forms" :key="row.uuid" class="vm-stack" :data-testid="`form-${row.slug}`">
                <div class="vm-cluster">
                    <span class="vm-truncate vm-truncate--fill" :title="row.name">{{ row.name }}</span>
                    <span class="vt-text-mono vm-wrap">{{ endpointBase }}{{ row.slug }}</span>
                    <span :data-testid="`state-${row.slug}`">
                        {{ row.active ? t('forms.active') : t('forms.inactive') }}
                    </span>
                    <span v-if="row.listName">{{ row.listName }} ({{ row.listContacts }})</span>

                    <VtButton variant="ghost" :data-testid="`edit-${row.slug}`" @click="edit(row)">
                        {{ t('forms.edit') }}
                    </VtButton>

                    <VtButton variant="ghost" :data-testid="`toggle-${row.slug}`" @click="toggle(row)">
                        {{ row.active ? t('forms.deactivate') : t('forms.activate') }}
                    </VtButton>

                    <VtButton
                        variant="ghost"
                        :data-testid="`snippet-${row.slug}`"
                        @click="snippetFor = snippetFor === row.uuid ? null : row.uuid"
                    >
                        {{ t('forms.snippet') }}
                    </VtButton>
                </div>

                <!-- A KÓD a szerveren áll össze: a mezőnevek a publikus végpont
                     szerződése, és tesztek őrzik. -->
                <pre v-if="snippetFor === row.uuid" class="vm-code" :data-testid="`snippet-body-${row.slug}`">{{
                    row.snippet
                }}</pre>
            </div>
        </VmCard>
    </AppLayout>
</template>
