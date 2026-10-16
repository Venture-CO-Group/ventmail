<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBadge, VtBanner, VtButton, VtCheckbox, VtField, VtInput, VtSelect, type VtSelectOption } from '@venture-co-group/ui';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../Form/VmCard.vue';
import VmFormActions from '../Form/VmFormActions.vue';
import VmFormRow from '../Form/VmFormRow.vue';
import type { ImportErrors, ImportField, ImportState } from './types';

/**
 * 2. LÉPÉS — oszlop-hozzárendelés: a fejléc alapján javasolt cél (7 nyelven), módosítható,
 * és új egyéni mező helyben létrehozható. A szerver ellenőrzi: pontosan egy e-mail oszlop.
 */
const props = defineProps<{
    state: ImportState;
    fields: ImportField[];
    builtinFields: string[];
    errors: ImportErrors;
}>();

const emit = defineEmits<{ (e: 'saved'): void; (e: 'back'): void; (e: 'errors', value: ImportErrors): void }>();

const { t } = useI18n({ useScope: 'global' });

const NEW_FIELD = '__new__';

const mapping = reactive<Record<number, string>>({});
const hasHeader = ref(props.state.profile.hasHeader);
const busy = ref(false);

const draft = reactive({ column: -1, label: '', key: '', type: 'text' as ImportField['type'] });
const draftError = ref<string | null>(null);

watch(
    () => [props.state.uuid, props.state.profile.sheet, JSON.stringify(props.state.mapping)],
    () => {
        for (const key of Object.keys(mapping)) delete mapping[Number(key)];
        for (const [column, target] of Object.entries(props.state.mapping)) mapping[Number(column)] = target;
        hasHeader.value = props.state.profile.hasHeader;
    },
    { immediate: true },
);

const options = computed<VtSelectOption[]>(() => [
    { value: 'skip', label: t('audience.import.target.skip') },
    { value: 'email', label: t('audience.import.target.email') },
    { value: 'first_name', label: t('audience.import.target.first_name') },
    { value: 'last_name', label: t('audience.import.target.last_name') },
    ...props.builtinFields.map((key) => ({ value: `attr:${key}`, label: t(`audience.import.target.${key}`) })),
    ...props.fields.map((field) => ({ value: `attr:${field.key}`, label: field.label })),
    { value: NEW_FIELD, label: t('audience.import.target.newField') },
]);

const typeOptions = computed<VtSelectOption[]>(() =>
    (['text', 'number', 'date', 'boolean'] as const).map((type) => ({ value: type, label: t(`audience.import.fieldType.${type}`) })),
);

const sheetOptions = computed<VtSelectOption[]>(() => props.state.profile.sheets.map((sheet) => ({ value: sheet, label: sheet })));

const columns = computed(() =>
    props.state.suggestions.map((suggestion) => ({
        ...suggestion,
        header: suggestion.header || t('audience.import.columnN', { n: suggestion.index + 1 }),
    })),
);

const emailCount = computed(() => Object.values(mapping).filter((target) => target === 'email').length);

function badge(index: number): { tone: 'success' | 'accent' | 'neutral'; text: string } {
    const suggestion = props.state.suggestions[index];
    const current = mapping[index];

    if (current?.startsWith('attr:') && !props.builtinFields.includes(current.slice(5)) && suggestion?.target !== current) {
        return { tone: 'accent', text: t('audience.import.badge.custom') };
    }

    if (suggestion?.target && suggestion.target === current) {
        return { tone: 'success', text: t('audience.import.badge.auto', { confidence: suggestion.confidence }) };
    }

    return { tone: 'neutral', text: current === 'skip' ? t('audience.import.badge.none') : t('audience.import.badge.manual') };
}

function onSelect(index: number, value: string | number): void {
    const target = String(value);

    if (target === NEW_FIELD) {
        const header = props.state.suggestions[index]?.header ?? '';

        draft.column = index;
        draft.label = header;
        draft.key = slug(header);
        draft.type = 'text';
        draftError.value = null;

        return;
    }

    mapping[index] = target;

    if (draft.column === index) draft.column = -1;
}

/** `Születésnap` → `szuletesnap`: a kulcs zárt alak (`[a-z][a-z0-9_]{0,39}`). */
function slug(value: string): string {
    const base = value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);

    return /^[a-z]/.test(base) ? base : base ? `f_${base}`.slice(0, 40) : '';
}

function createField(): void {
    draftError.value = null;

    router.post('/audience/import/fields', { key: draft.key, label: draft.label, type: draft.type }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            mapping[draft.column] = `attr:${draft.key}`;
            draft.column = -1;
        },
        onError: (errors) => (draftError.value = errors.key ?? errors.label ?? errors.type ?? null),
    });
}

function changeSheet(sheet: string | number): void {
    router.post(`/audience/import/${props.state.uuid}/sheet`, { sheet: String(sheet) }, { preserveScroll: true });
}

function save(): void {
    busy.value = true;

    router.post(`/audience/import/${props.state.uuid}/mapping`, { mapping: { ...mapping }, hasHeader: hasHeader.value }, {
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
    <section data-testid="import-step-columns">
        <h2 class="vm-wrap">{{ t('audience.import.step2.title') }}</h2>
        <p class="vt-text-muted">{{ t('audience.import.step2.subtitle') }}</p>

        <VmFormRow>
            <VtField v-if="state.profile.sheets.length > 1" :label="t('audience.import.sheet')" :error="errors.sheet">
                <VtSelect
                    :model-value="state.profile.sheet ?? ''"
                    :options="sheetOptions"
                    data-testid="import-sheet"
                    @update:model-value="changeSheet"
                />
            </VtField>
            <VtCheckbox v-model="hasHeader" data-testid="import-has-header">{{ t('audience.import.hasHeader') }}</VtCheckbox>
        </VmFormRow>

        <div class="vm-import-mapping" data-testid="import-mapping" role="table" :aria-label="t('audience.import.step2.title')">
            <div class="vm-import-mapping__row vm-import-mapping__row--head" role="row">
                <span role="columnheader">{{ t('audience.import.col.file') }}</span>
                <span role="columnheader">{{ t('audience.import.col.sample') }}</span>
                <span role="columnheader">{{ t('audience.import.col.target') }}</span>
                <span role="columnheader">{{ t('audience.import.col.suggestion') }}</span>
            </div>

            <div v-for="column in columns" :key="column.index" class="vm-import-mapping__row" role="row">
                <strong class="vm-wrap" role="cell">{{ column.header }}</strong>
                <span class="vt-mono vm-truncate vm-truncate--fill" role="cell" :title="column.sample">{{ column.sample }}</span>
                <div role="cell">
                    <VtSelect
                        :model-value="mapping[column.index] ?? 'skip'"
                        :options="options"
                        :data-testid="`mapping-select-${column.index}`"
                        @update:model-value="onSelect(column.index, $event)"
                    />
                </div>
                <div role="cell">
                    <VtBadge :tone="badge(column.index).tone">{{ badge(column.index).text }}</VtBadge>
                </div>
            </div>
        </div>

        <VmCard v-if="draft.column >= 0" data-testid="new-field-form">
            <h3>{{ t('audience.import.newField.title', { name: draft.label }) }}</h3>
            <VmFormRow>
                <VtField :label="t('audience.import.newField.name')">
                    <VtInput v-model="draft.label" data-testid="new-field-label" />
                </VtField>
                <VtField :label="t('audience.import.newField.key')" :hint="t('audience.import.newField.keyHint')">
                    <VtInput v-model="draft.key" data-testid="new-field-key" />
                </VtField>
                <VtField :label="t('audience.import.newField.type')">
                    <VtSelect v-model="draft.type" :options="typeOptions" data-testid="new-field-type" />
                </VtField>
            </VmFormRow>
            <VtBanner v-if="draftError" tone="danger" role="alert" data-testid="new-field-error">{{ draftError }}</VtBanner>
            <VmFormActions>
                <VtButton :disabled="!draft.key || !draft.label.trim()" data-testid="new-field-create" @click="createField">
                    {{ t('audience.import.newField.create') }}
                </VtButton>
                <span class="vt-text-muted">{{ t('audience.import.newField.hint') }}</span>
            </VmFormActions>
        </VmCard>

        <VtBanner v-if="emailCount !== 1" tone="warning" role="status" data-testid="import-need-email">
            {{ t('audience.import.needEmail') }}
        </VtBanner>
        <VtBanner v-if="errors.mapping" tone="danger" role="alert">{{ errors.mapping }}</VtBanner>

        <VmFormActions>
            <VtButton variant="secondary" data-testid="import-back" @click="emit('back')">{{ t('audience.import.back') }}</VtButton>
            <VtButton :disabled="busy || emailCount !== 1" data-testid="import-next" @click="save">{{ t('audience.import.next') }}</VtButton>
        </VmFormActions>
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
h3,
p {
    margin: 0;
}

.vt-text-muted {
    color: var(--vt-text-secondary);
}

.vm-import-mapping {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.vm-import-mapping__row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(calc(var(--vt-space-12) * 2) * 1.5)), 1fr));
    align-items: center;
    gap: var(--vt-space-3);
    padding: var(--vt-space-3) 0;
    border-bottom: thin solid var(--vt-border);
    min-width: 0;
}

.vm-import-mapping__row--head {
    font-size: var(--vt-type-caption-size);
    color: var(--vt-text-secondary);
    text-transform: uppercase;
}
</style>
