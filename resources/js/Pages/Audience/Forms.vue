<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCheckbox, VtField, VtInput, VtSelect, VtTextarea } from '@venture/ui';
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
    fields: BuilderField[];
    page: PageTexts;
    hostedUrl: string;
    iframe: string;
    requireTiming: boolean;
}

/** v1.1 · ŰRLAP-ÉPÍTŐ: egy mező (név-oszlop vagy `attr:<kulcs>` egyéni mező). */
interface BuilderField {
    key: string;
    label: string;
    required: boolean;
}

interface PageTexts {
    title?: string;
    intro?: string;
    button?: string;
    successTitle?: string;
    successMessage?: string;
}

const props = defineProps<{
    forms: FormRow[];
    lists: Array<{ uuid: string; name: string }>;
    endpointBase: string;
    customFields: Array<{ key: string; label: string; type: string }>;
}>();

const { t } = useI18n();

const blank = () => ({
    name: '',
    listUuid: '',
    minSeconds: '5',
    policyVersion: '',
    consentText: t('forms.consentDefault'),
    redirectUrl: '',
    requireTiming: true,
    fields: [{ key: 'first_name', label: '', required: false }] as BuilderField[],
    page: { title: '', intro: '', button: '', successTitle: '', successMessage: '' } as Required<PageTexts>,
});

const form = ref(blank());
const embedTab = ref<'hosted' | 'iframe' | 'html'>('hosted');
const newField = ref('');

/* A VÁLASZTHATÓ mezők: a két név-oszlop és a workspace egyéni mezői — ami már rajta van, nem. */
const fieldChoices = computed(() =>
    [
        { key: 'first_name', label: t('forms.firstName') },
        { key: 'last_name', label: t('forms.lastName') },
        ...props.customFields.map((field) => ({ key: field.key, label: field.label })),
    ].filter((choice) => !form.value.fields.some((field) => field.key === choice.key)),
);

const fieldName = (key: string): string =>
    key === 'first_name'
        ? t('forms.firstName')
        : key === 'last_name'
          ? t('forms.lastName')
          : (props.customFields.find((field) => field.key === key)?.label ?? key);

function addField(): void {
    const key = newField.value || fieldChoices.value[0]?.key;

    if (!key) return;

    form.value.fields.push({ key, label: '', required: false });
    newField.value = '';
}

function moveField(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= form.value.fields.length) return;

    const fields = [...form.value.fields];
    [fields[index], fields[target]] = [fields[target], fields[index]];
    form.value.fields = fields;
}
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
        requireTiming: form.value.requireTiming,
        fields: form.value.fields.map((field) => ({ key: field.key, label: field.label.trim(), required: field.required })),
        page: { ...form.value.page },
    };
}

function submit(): void {
    const url = editing.value ? `/forms/${editing.value}` : '/forms';

    router.post(url, payload() as never, {
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
        requireTiming: row.requireTiming,
        fields: row.fields.map((field) => ({ ...field })),
        page: { title: '', intro: '', button: '', successTitle: '', successMessage: '', ...row.page },
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

                <!-- v1.1 · ŰRLAP-ÉPÍTŐ: mezők + oldal-szövegek + élő előnézet. -->
                <VmFormSection data-testid="form-builder">
                    <h3>{{ t('forms.fields') }}</h3>
                    <p class="vm-form-hint">{{ t('forms.fieldsHint') }}</p>
                    <p class="vm-banner" role="note" data-testid="form-doi">{{ t('forms.doiAlways') }}</p>
                    <VtCheckbox v-model="form.requireTiming" data-testid="form-require-timing">{{ t('forms.requireTiming') }}</VtCheckbox>
                    <p class="vm-form-hint">{{ t('forms.requireTimingHint') }}</p>

                    <ol class="vm-form-fields">
                        <li class="vm-form-fields__fixed">{{ t('forms.emailField') }}</li>
                        <li v-for="(field, index) in form.fields" :key="field.key" class="vm-form-fields__row" :data-testid="`builder-field-${field.key}`">
                            <strong class="vm-truncate" :title="fieldName(field.key)">{{ fieldName(field.key) }}</strong>
                            <label class="vm-visually-hidden" :for="`field-label-${index}`">{{ t('forms.fieldLabel') }}</label>
                            <VtInput :id="`field-label-${index}`" v-model="field.label" :placeholder="t('forms.fieldLabel')" :data-testid="`builder-label-${field.key}`" />
                            <VtCheckbox v-model="field.required" :data-testid="`builder-required-${field.key}`">{{ t('forms.fieldRequired') }}</VtCheckbox>
                            <span class="vm-form-fields__actions">
                                <VtButton variant="ghost" size="sm" :disabled="index === 0" :aria-label="t('forms.moveUp')" @click="moveField(index, -1)">↑</VtButton>
                                <VtButton variant="ghost" size="sm" :disabled="index === form.fields.length - 1" :aria-label="t('forms.moveDown')" @click="moveField(index, 1)">↓</VtButton>
                                <VtButton variant="ghost" size="sm" :aria-label="t('forms.removeField')" :data-testid="`builder-remove-${field.key}`" @click="form.fields.splice(index, 1)">✕</VtButton>
                            </span>
                        </li>
                    </ol>

                    <div v-if="fieldChoices.length" class="vm-cluster">
                        <label class="vm-visually-hidden" for="builder-new-field">{{ t('forms.addField') }}</label>
                        <VtSelect
                            id="builder-new-field"
                            v-model="newField"
                            :options="fieldChoices.map((choice) => ({ value: choice.key, label: choice.label }))"
                            :placeholder="t('forms.addField')"
                            data-testid="builder-new-field"
                        />
                        <VtButton variant="subtle" size="sm" data-testid="builder-add-field" @click="addField">{{ t('forms.addField') }}</VtButton>
                    </div>

                    <h3>{{ t('forms.pageTexts') }}</h3>
                    <VmFormRow>
                        <VtField :label="t('forms.pageTitle')">
                            <VtInput v-model="form.page.title" data-testid="form-page-title" />
                        </VtField>
                        <VtField :label="t('forms.pageButton')">
                            <VtInput v-model="form.page.button" data-testid="form-page-button" />
                        </VtField>
                    </VmFormRow>
                    <VtField :label="t('forms.pageIntro')">
                        <VtTextarea v-model="form.page.intro" :rows="2" data-testid="form-page-intro" />
                    </VtField>
                    <VmFormRow>
                        <VtField :label="t('forms.successTitle')">
                            <VtInput v-model="form.page.successTitle" data-testid="form-success-title" />
                        </VtField>
                        <VtField :label="t('forms.successMessage')">
                            <VtInput v-model="form.page.successMessage" data-testid="form-success-message" />
                        </VtField>
                    </VmFormRow>

                    <h3>{{ t('forms.preview') }}</h3>
                    <div class="vm-form-preview" data-testid="form-preview" aria-hidden="true">
                        <strong>{{ form.page.title || form.name || t('forms.title') }}</strong>
                        <small v-if="form.page.intro">{{ form.page.intro }}</small>
                        <span class="vm-form-preview__field">{{ t('forms.emailField') }}</span>
                        <span v-for="field in form.fields" :key="field.key" class="vm-form-preview__field">
                            {{ field.label || fieldName(field.key) }}{{ field.required ? ' *' : '' }}
                        </span>
                        <small>☐ {{ form.consentText }}</small>
                        <span class="vm-form-preview__button">{{ form.page.button || t('forms.submitLabel') }}</span>
                    </div>
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
                        @click="
                            snippetFor = snippetFor === row.uuid ? null : row.uuid;
                            embedTab = 'html';
                        "
                    >
                        {{ t('forms.snippet') }}
                    </VtButton>
                </div>

                <!-- A KÓD a szerveren áll össze: a mezőnevek a publikus végpont
                     szerződése, és tesztek őrzik. -->
                <div v-if="snippetFor === row.uuid" class="vm-stack" :data-testid="`embed-${row.slug}`">
                    <div class="vm-cluster" role="group" :aria-label="t('forms.snippet')">
                        <VtButton :variant="embedTab === 'hosted' ? 'subtle' : 'ghost'" size="sm" :data-testid="`embed-tab-hosted-${row.slug}`" @click="embedTab = 'hosted'">{{ t('forms.hostedLink') }}</VtButton>
                        <VtButton :variant="embedTab === 'iframe' ? 'subtle' : 'ghost'" size="sm" :data-testid="`embed-tab-iframe-${row.slug}`" @click="embedTab = 'iframe'">{{ t('forms.iframeCode') }}</VtButton>
                        <VtButton :variant="embedTab === 'html' ? 'subtle' : 'ghost'" size="sm" :data-testid="`embed-tab-html-${row.slug}`" @click="embedTab = 'html'">{{ t('forms.htmlCode') }}</VtButton>
                    </div>
                    <p v-if="embedTab === 'hosted'" class="vm-cluster">
                        <span class="vt-text-mono vm-wrap" :data-testid="`hosted-url-${row.slug}`">{{ row.hostedUrl }}</span>
                        <a :href="row.hostedUrl" target="_blank" rel="noopener" :data-testid="`hosted-open-${row.slug}`">{{ t('forms.openHosted') }}</a>
                    </p>
                    <pre v-else-if="embedTab === 'iframe'" class="vm-code" :data-testid="`iframe-body-${row.slug}`">{{ row.iframe }}</pre>
                    <template v-else>
                        <p v-if="row.requireTiming" class="vm-banner vm-banner--warning" role="status" :data-testid="`html-needs-timing-${row.slug}`">
                            {{ t('forms.htmlNeedsTiming') }}
                        </p>
                        <!-- A KÓD a szerveren áll össze: a mezőnevek a publikus végpont
                             szerződése, és tesztek őrzik. -->
                        <pre class="vm-code" :data-testid="`snippet-body-${row.slug}`">{{ row.snippet }}</pre>
                    </template>
                </div>
            </div>
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-form-hint {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-form-fields {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-form-fields__fixed,
.vm-form-fields__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    padding: var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    min-width: 0;
}

.vm-form-fields__fixed {
    color: var(--vt-text-muted);
    border-style: dashed;
}

.vm-form-fields__row > strong {
    flex: 1 1 calc(var(--vt-space-24) + var(--vt-space-8));
}

.vm-form-fields__actions {
    display: flex;
    gap: var(--vt-space-1);
}

.vm-form-preview {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    padding: var(--vt-space-6);
    border: thin solid var(--vt-glass-base-stroke);
    border-radius: var(--vt-radius-lg);
    background-color: var(--vt-glass-base-fill);
    overflow-wrap: anywhere;
}

.vm-form-preview small {
    color: var(--vt-text-muted);
}

.vm-form-preview__field {
    padding: var(--vt-space-2) var(--vt-space-3);
    border: thin solid var(--vt-border-strong);
    border-radius: var(--vt-radius-md);
    color: var(--vt-text-subtle);
    font-size: var(--vt-fs-body-sm);
}

.vm-form-preview__button {
    align-self: flex-start;
    padding: var(--vt-space-2) var(--vt-space-5);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-accent);
    color: var(--vt-on-accent);
    font-weight: var(--vt-weight-semibold);
}
</style>
