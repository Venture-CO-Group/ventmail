<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtSelect, VtTextarea } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import SegmentGroupEditor from '../../Components/Segment/SegmentGroupEditor.vue';
import { countRules, normalise, type NamedRef, type SegmentCatalog, type SegmentGroup } from '../../Components/Segment/types';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * SZEGMENSÉPÍTŐ (v1.1 · 5. modul): vizuális ÉS/VAGY csoportok, élő számláló, mentés.
 *
 * Az ELŐNÉZET ugyanazt a motort hívja, amit a kampány és a journey: amit itt lát a
 * felhasználó, az kapja a levelet. A szerkesztés debounce-szal kér új számot; a hibás
 * definíció a motor üzenetét mutatja (422), nem egy csendes nullát.
 */
const props = defineProps<{
    segment: { uuid: string; name: string; description: string | null; definition: SegmentGroup; estimatedSize: number | null } | null;
    catalog: SegmentCatalog;
    lists: NamedRef[];
    tags: NamedRef[];
}>();

const { t } = useI18n();
const format = useFormat();

const name = ref(props.segment?.name ?? '');
const description = ref(props.segment?.description ?? '');
const definition = reactive<SegmentGroup>(
    props.segment?.definition
        ? (JSON.parse(JSON.stringify(props.segment.definition)) as SegmentGroup)
        : { match: 'all', rules: [] },
);

if (!Array.isArray(definition.rules)) definition.rules = [];
if (definition.match !== 'any') definition.match = 'all';

const count = ref<number | null>(props.segment?.estimatedSize ?? null);
const sample = ref<Array<{ uuid: string; email: string; name: string; status: string }>>([]);
const previewError = ref<string | null>(null);
const loading = ref(false);
const saving = ref(false);

const rules = computed(() => countRules(definition));
const canAddRule = computed(() => rules.value < props.catalog.maxRules);

let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

async function preview(): Promise<void> {
    const mine = ++sequence;
    loading.value = true;

    try {
        const response = await axios.post<{ count: number; sample: typeof sample.value }>('/audience/segments/preview', {
            definition: normalise(definition),
        });

        // A KÉSVE érkező régi válasz nem írhatja felül az újabbat.
        if (mine !== sequence) return;

        count.value = response.data.count;
        sample.value = response.data.sample;
        previewError.value = null;
    } catch (error) {
        if (mine !== sequence) return;

        const data = (error as AxiosError<{ errors?: Record<string, string[]>; message?: string }>).response?.data;
        previewError.value = data?.errors?.definition?.[0] ?? data?.message ?? t('segments.previewFailed');
        count.value = null;
    } finally {
        if (mine === sequence) loading.value = false;
    }
}

function changed(): void {
    clearTimeout(timer);
    timer = setTimeout(preview, 450);
}

onMounted(preview);
onBeforeUnmount(() => clearTimeout(timer));

/* A SZEGMENS TALÁLATAINAK tömeges címkézése (csak mentett szegmensen: a mentett definíció
   számít, nem a félkész szerkesztés). A szerver egy halmaz-utasítással írja. */
const bulkAction = ref('tag');
const bulkTag = ref(props.tags[0]?.uuid ?? '__new__');
const bulkNewTag = ref('');

function applyBulk(): void {
    if (!props.segment) return;

    router.post(
        '/audience/contacts/bulk-tags',
        {
            action: bulkAction.value,
            tagUuid: bulkTag.value === '__new__' ? null : bulkTag.value,
            tagName: bulkTag.value === '__new__' ? bulkNewTag.value.trim() : null,
            target: { type: 'segment', uuid: props.segment.uuid },
        },
        { preserveScroll: true, onSuccess: () => (bulkNewTag.value = '') },
    );
}

function save(): void {
    if (!name.value.trim()) return;

    saving.value = true;

    // Az Inertia `RequestPayload` index-szignatúrát vár: a fa JSON-ként megy át változatlanul.
    const payload = JSON.parse(JSON.stringify({ name: name.value.trim(), description: description.value.trim() || null, definition: normalise(definition) })) as Record<string, string | null>;
    const options = { preserveScroll: true, onFinish: () => (saving.value = false) };

    props.segment
        ? router.put(`/audience/segments/${props.segment.uuid}`, payload, options)
        : router.post('/audience/segments', payload, options);
}
</script>

<template>
    <Head :title="segment ? segment.name : t('segments.new')" />

    <AppLayout>
        <VmPageHeader :eyebrow="t('segments.eyebrow')" :title="segment ? segment.name : t('segments.new')" :lead="t('segments.builderLead')">
            <VtButton variant="ghost" @click="router.visit('/audience/segments')">{{ t('segments.backToList') }}</VtButton>
        </VmPageHeader>

        <div class="vm-seg-layout">
            <div class="vm-seg-layout__main">
                <VmCard>
                    <VmFormSection>
                        <VtField :label="t('segments.name')">
                            <VtInput v-model="name" data-testid="segment-name" :placeholder="t('segments.namePlaceholder')" />
                        </VtField>
                        <VtField :label="t('segments.description')">
                            <VtTextarea v-model="description" :rows="2" data-testid="segment-description" />
                        </VtField>
                    </VmFormSection>
                </VmCard>

                <VmCard>
                    <SegmentGroupEditor
                        :group="definition"
                        :catalog="catalog"
                        :lists="lists"
                        :tags="tags"
                        :depth="1"
                        path=""
                        :can-add-rule="canAddRule"
                        @change="changed"
                    />
                    <p class="vm-seg-limit">{{ t('segments.limit', { count: rules, max: catalog.maxRules }) }}</p>
                </VmCard>
            </div>

            <aside class="vm-seg-layout__side">
                <VmCard glass glow>
                    <p class="vm-seg-count__label">{{ t('segments.matching') }}</p>
                    <p class="vm-seg-count" data-testid="segment-count" :data-loading="loading ? 'true' : 'false'" aria-live="polite">
                        {{ count === null ? '—' : format.number(count) }}
                    </p>
                    <p v-if="previewError" class="vm-banner vm-banner--danger" role="alert" data-testid="segment-error">{{ previewError }}</p>
                    <p class="vm-seg-count__hint">{{ t('segments.reachableHint') }}</p>

                    <ul v-if="sample.length" class="vm-seg-sample" data-testid="segment-sample">
                        <li v-for="row in sample" :key="row.uuid" class="vm-truncate" :title="row.email">
                            <span class="vt-text-mono">{{ row.email }}</span>
                        </li>
                    </ul>

                    <VmFormActions>
                        <VtButton glow :disabled="!name.trim() || saving || previewError !== null" data-testid="segment-save" @click="save">
                            {{ t('segments.save') }}
                        </VtButton>
                    </VmFormActions>
                </VmCard>

                <VmCard v-if="segment" data-testid="segment-bulk">
                    <h2>{{ t('segments.bulkTitle') }}</h2>
                    <p class="vm-seg-count__hint">{{ t('segments.bulkHint') }}</p>
                    <label class="vm-visually-hidden" for="segment-bulk-action">{{ t('audience.bulkActionLabel') }}</label>
                    <VtSelect
                        id="segment-bulk-action"
                        v-model="bulkAction"
                        :options="[
                            { value: 'tag', label: t('audience.bulkTag') },
                            { value: 'untag', label: t('audience.bulkUntag') },
                        ]"
                        data-testid="segment-bulk-action"
                    />
                    <label class="vm-visually-hidden" for="segment-bulk-tag">{{ t('audience.bulkTagLabel') }}</label>
                    <VtSelect
                        id="segment-bulk-tag"
                        v-model="bulkTag"
                        :options="[...tags.map((tag) => ({ value: tag.uuid, label: tag.name })), { value: '__new__', label: t('audience.bulkNewTag') }]"
                        data-testid="segment-bulk-tag"
                    />
                    <VtInput v-if="bulkTag === '__new__'" v-model="bulkNewTag" :placeholder="t('audience.bulkNewTagPlaceholder')" :aria-label="t('audience.bulkNewTag')" data-testid="segment-bulk-new-tag" />
                    <VmFormActions>
                        <VtButton variant="subtle" :disabled="bulkTag === '__new__' && !bulkNewTag.trim()" data-testid="segment-bulk-apply" @click="applyBulk">
                            {{ t('audience.bulkApply') }}
                        </VtButton>
                    </VmFormActions>
                </VmCard>
            </aside>
        </div>
    </AppLayout>
</template>

<style scoped>
.vm-seg-layout {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--vt-space-6);
}

.vm-seg-layout__main {
    display: flex;
    flex: 3 1 calc(var(--vt-space-32) * 4);
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-seg-layout__side {
    display: flex;
    flex: 1 1 calc(var(--vt-space-32) * 2);
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-seg-count__label,
.vm-seg-count__hint,
.vm-seg-limit {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-seg-count {
    margin: 0;
    font-family: var(--vt-font-display);
    font-size: var(--vt-fs-h2);
    font-weight: var(--vt-weight-semibold);
    transition: opacity var(--vt-duration-fast) var(--vt-ease-standard);
}

.vm-seg-count[data-loading='true'] {
    opacity: 0.6;
}

.vm-seg-sample {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: var(--vt-fs-body-sm);
}
</style>
