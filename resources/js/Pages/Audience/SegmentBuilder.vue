<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { VtBadge, VtBanner, VtButton, VtEmptyState, VtField, VtIcon, VtInput, VtModal, VtPageHeader, VtSelect, VtTextarea } from '@venture-co-group/ui';
import axios, { AxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import SegmentGroupEditor from '../../Components/Segment/SegmentGroupEditor.vue';
import { countRules, normalise, type NamedRef, type SegmentCatalog, type SegmentGroup } from '../../Components/Segment/types';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * SZEGMENSEK (v1.1 · 5. modul; Figma Unified UI v2 · Segments): balra a szegmenslista, jobbra a
 * kiválasztott szegmens szerkesztője — vizuális ÉS/VAGY csoportok, élő számláló, mentés, törlés.
 *
 * Az ELŐNÉZET ugyanazt a motort hívja, amit a kampány és a journey: amit itt lát a
 * felhasználó, az kapja a levelet. A szerkesztés debounce-szal kér új számot; a hibás
 * definíció a motor üzenetét mutatja (422), nem egy csendes nullát.
 */
interface SegmentRow {
    uuid: string;
    name: string;
    description: string | null;
    estimatedSize: number | null;
    campaigns: number;
}

const props = defineProps<{
    segments: SegmentRow[];
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

/* TÖRLÉS: a kampány által célzott szegmenst a szerver elutasítja (a használat a listában látszik). */
const deleting = ref(false);
const usage = computed(() => props.segments.find((row) => row.uuid === props.segment?.uuid)?.campaigns ?? 0);

function remove(): void {
    if (!props.segment) return;

    router.delete(`/audience/segments/${props.segment.uuid}`, { preserveScroll: true, onFinish: () => (deleting.value = false) });
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
    <Head :title="segment ? segment.name : t('segments.title')" />

    <AppLayout>
        <VtPageHeader :overline="t('nav.studio')" :title="t('segments.title')" :description="t('page.segments.lead')" data-testid="segments-header">
            <template #actions>
                <VtButton data-testid="segment-new" @click="router.visit('/audience/segments/new')">
                    <VmIcon name="plus" /> {{ t('segments.new') }}
                </VtButton>
            </template>
        </VtPageHeader>

        <div class="vm-seg-layout">
            <!-- MASTER: a szegmensek (Figma: „Segments" kártya, kiválasztott sor kiemelve). -->
            <VmCard class="vm-seg-layout__list" :title="t('segments.title')" flush>
                <VtEmptyState v-if="!segments.length" :title="t('segments.empty')" icon="filter" data-testid="segments-empty" />
                <ul v-else class="vm-seg-list">
                    <li v-for="row in segments" :key="row.uuid" :data-testid="`segment-row-${row.uuid}`">
                        <Link
                            :href="`/audience/segments/${row.uuid}`"
                            class="vm-seg-list__row"
                            :class="{ 'is-active': row.uuid === segment?.uuid }"
                            :aria-current="row.uuid === segment?.uuid ? 'page' : undefined"
                            :title="row.description ?? row.name"
                            :data-testid="`segment-open-${row.uuid}`"
                        >
                            <VtIcon name="filter" :size="16" class="vm-seg-list__icon" />
                            <span class="vm-truncate vm-seg-list__name">{{ row.name }}</span>
                            <VtBadge tone="neutral" :dot="false">{{ row.estimatedSize === null ? '—' : format.number(row.estimatedSize) }}</VtBadge>
                        </Link>
                    </li>
                </ul>
            </VmCard>

            <!-- DETAIL: a kiválasztott (vagy új) szegmens szerkesztője. -->
            <div class="vm-seg-layout__main">
                <VmCard data-testid="segment-editor">
                    <template #header>
                        <div class="vm-seg-head">
                            <h2 class="vm-seg-head__title vm-truncate">{{ name.trim() || (segment ? segment.name : t('segments.new')) }}</h2>
                            <p class="vm-seg-head__size" aria-live="polite">
                                {{ t('segments.matching') }}:
                                <strong class="vm-seg-count" data-testid="segment-count" :data-loading="loading ? 'true' : 'false'">{{ count === null ? '—' : format.number(count) }}</strong>
                            </p>
                        </div>
                    </template>
                    <template #actions>
                        <VtButton v-if="segment" variant="ghost" size="sm" :data-testid="`segment-delete-${segment.uuid}`" @click="deleting = true">{{ t('segments.delete') }}</VtButton>
                        <VtButton size="sm" icon="check" :disabled="!name.trim() || saving || previewError !== null" data-testid="segment-save" @click="save">
                            {{ t('segments.save') }}
                        </VtButton>
                    </template>

                    <VmFormSection>
                        <VtField :label="t('segments.name')">
                            <VtInput v-model="name" data-testid="segment-name" :placeholder="t('segments.namePlaceholder')" />
                        </VtField>
                        <VtField :label="t('segments.description')">
                            <VtTextarea v-model="description" :rows="2" data-testid="segment-description" />
                        </VtField>
                    </VmFormSection>

                    <VtBanner v-if="previewError" tone="danger" role="alert" data-testid="segment-error">{{ previewError }}</VtBanner>

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
                    <p class="vm-seg-limit">{{ t('segments.limit', { count: rules, max: catalog.maxRules }) }} · {{ t('segments.reachableHint') }}</p>
                </VmCard>

                <VmCard v-if="sample.length" :title="t('segments.matching')" :heading-level="3">
                    <ul class="vm-seg-sample" data-testid="segment-sample">
                        <li v-for="row in sample" :key="row.uuid" class="vm-truncate" :title="row.email">
                            <span class="vt-mono">{{ row.email }}</span>
                        </li>
                    </ul>
                </VmCard>

                <VmCard v-if="segment" data-testid="segment-bulk" :title="t('segments.bulkTitle')" :heading-level="3">
                    <p class="vm-seg-limit">{{ t('segments.bulkHint') }}</p>
                    <div class="vm-seg-bulk">
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
                        <VtButton variant="secondary" :disabled="bulkTag === '__new__' && !bulkNewTag.trim()" data-testid="segment-bulk-apply" @click="applyBulk">
                            {{ t('audience.bulkApply') }}
                        </VtButton>
                    </div>
                </VmCard>
            </div>
        </div>

        <VtModal :open="deleting" :title="t('segments.deleteTitle')" @close="deleting = false">
            <p>{{ t('segments.deleteConfirm', { name: segment?.name ?? '' }) }}</p>
            <p v-if="usage > 0">{{ t('segments.usedBy', { count: usage }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="deleting = false">{{ t('segments.cancel') }}</VtButton>
                <VtButton data-testid="segment-delete-confirm" @click="remove">{{ t('segments.delete') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
/* Figma Segments: 340 px lista + a szerkesztő; keskeny nézetben egymás alatt. */
.vm-seg-layout {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--vt-space-6);
}

.vm-seg-layout__list {
    flex: 1 1 calc(var(--vt-space-12) * 7);
    max-inline-size: calc(var(--vt-space-12) * 7 + var(--vt-space-6));
}

.vm-seg-layout__main {
    display: flex;
    flex: 999 1 calc(var(--vt-space-12) * 10);
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-seg-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-seg-list li + li {
    border-block-start: thin solid var(--vt-border-subtle);
}

.vm-seg-list__row {
    display: flex;
    align-items: center;
    gap: var(--vt-space-3);
    padding: var(--vt-space-3) var(--vt-space-5);
    color: var(--vt-text-primary);
    font-size: var(--vt-type-body-s-medium-size);
    font-weight: var(--vt-type-body-s-medium-weight);
    text-decoration: none;
}

.vm-seg-list__row:hover {
    background: var(--vt-surface-hover);
}

.vm-seg-list__row.is-active {
    background: var(--vt-accent-soft);
}

.vm-seg-list__icon {
    color: var(--vt-text-secondary);
}

.vm-seg-list__row.is-active .vm-seg-list__icon {
    color: var(--vt-accent);
}

.vm-seg-list__name {
    flex: 1 1 auto;
    min-width: 0;
}

.vm-seg-head {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-seg-head__title {
    margin: 0;
    font: var(--vt-type-heading-h3);
    letter-spacing: var(--vt-type-heading-h3-tracking);
}

.vm-seg-head__size,
.vm-seg-limit {
    margin: 0;
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    line-height: var(--vt-type-caption-line-height);
}

.vm-seg-count {
    color: var(--vt-text-primary);
    font-variant-numeric: tabular-nums;
    transition: opacity var(--vt-duration-fast) var(--vt-ease-standard);
}

.vm-seg-count[data-loading='true'] {
    opacity: 0.6;
}

.vm-seg-bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vm-seg-sample {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: var(--vt-type-body-s-size);
}
</style>
