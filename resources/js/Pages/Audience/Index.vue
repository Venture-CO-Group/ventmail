<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtBadge, VtBanner, VtButton, VtCheckbox, VtField, VtInput, VtSegmented, VtSelect, type VtColumn } from '@venture-co-group/ui';
import axios from 'axios';
import VmStat from '../../Components/Form/VmStat.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import VmBarList from '../../Components/Studio/VmBarList.vue';
import VmGrowthBars, { type GrowthRow } from '../../Components/Studio/VmGrowthBars.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmPager from '../../Components/Form/VmPager.vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * KÖZÖNSÉG. A consent-állapot a kapcsolat MELLETT van, nem külön képernyőn: a marketing
 * küldés jogalapja ez, és aki nincs megerősítve, annak a 3. kapu nem enged marketinget.
 */
interface ContactRow {
    uuid: string;
    email: string;
    name: string;
    status: string;
    consent: string;
    validation: string | null;
    createdAt: string | null;
    source: string | null;
    tags: string[];
}

interface Paged<T> {
    items: T[];
    page: number;
    last: number;
    total: number;
    search: string;
}

const props = defineProps<{
    search: string;
    // v1.1 · D284: szerveroldali lapozás + keresés (`App\\Support\\Paged`).
    lists: Paged<{
        uuid: string;
        name: string;
        description: string | null;
        fromEmail: string | null;
        fromName: string | null;
        contacts: number;
    }>;
    segments: Paged<{ uuid: string; name: string; description: string | null; estimatedSize: number | null; estimatedAt: string | null }>;
    tags: Array<{ uuid: string; name: string }>;
    fields: Array<{ uuid: string; key: string; label: string; type: string; preferenceVisible: boolean }>;
    contacts: { items: ContactRow[]; nextCursor: string | null };
}>();

const { t, te } = useI18n();
const code = useCodes();
const format = useFormat();

const search = ref(props.search);
// A szerver validációs hibái (pl. „a név már foglalt") a mező alatt — nem csendes elnyelés.
const inertiaPage = usePage();
const pageErrors = computed(() => (inertiaPage.props.errors ?? {}) as Record<string, string | undefined>);
const listName = ref('');

/*
 | A LISTA NEVE ÉS LEÍRÁSA a CÍMZETTNEK is megjelenik (a preference center a kontakt saját
 | listáit sorolja fel), ezért szerkeszthetőnek kell lennie — soronként, hogy látható
 | legyen, melyik sort írjuk át.
 */
const editingList = ref<string | null>(null);
const listForm = ref({ name: '', description: '', fromEmail: '', fromName: '' });

type Row = { uuid: string; select: string; email: string; name: string; status: string; statusCode: string; consent: string; consentCode: string; tags: string; validation: string };

const columns: VtColumn<Row>[] = [
    { key: 'select', label: t('audience.select') },
    { key: 'email', label: t('audience.email'), mono: true },
    { key: 'name', label: t('audience.name') },
    { key: 'status', label: t('audience.status') },
    { key: 'consent', label: t('audience.consent') },
    { key: 'tags', label: t('audience.tagsColumn') },
    { key: 'validation', label: t('audience.validation') },
];

const rows = computed<Row[]>(() =>
    props.contacts.items.map((contact) => ({
        uuid: contact.uuid,
        select: '',
        email: contact.email,
        name: contact.name || '—',
        // A GÉPI kód helyett a fordított felirat (D284): „subscribed" / „none" nem felületi szöveg.
        statusCode: contact.status,
        consentCode: contact.consent,
        status: te(`segments.value.status.${contact.status}`) ? t(`segments.value.status.${contact.status}`) : contact.status,
        consent: te(`segments.value.consent.${contact.consent}`) ? t(`segments.value.consent.${contact.consent}`) : contact.consent,
        tags: contact.tags.join(', ') || '—',
        validation: contact.validation ?? '—',
    })),
);

// A MEGERŐSÍTÉS NÉLKÜLI kapcsolat a kampány csendes vesztesége: kimegy a lista fele, és a
// marketinges a naplóban keresi, miért.
const unconfirmed = computed(() => props.contacts.items.filter((contact) => contact.consent !== 'confirmed').length);

/*
 | KÖZÖNSÉG-DASHBOARD (v1.1 · 5. modul): a szám a szerver aggregátumából jön (JSON-végpont),
 | nem a lapon lévő 50 sorból. A növekedési ablak választható (7 / 30 / 90 / 365 nap).
 */
interface Insights {
    days: number;
    totals: { total: number; subscribed: number; pending: number; unsubscribed: number; cleaned: number };
    growth: GrowthRow[];
    sources: Array<{ key: string; count: number }>;
    engagement: Array<{ key: string; count: number }>;
    activeWindowDays: number;
}

const insights = ref<Insights | null>(null);
const insightsError = ref(false);
const windowDays = ref('30');

const windowOptions = computed(() => ['7', '30', '90', '365'].map((value) => ({ value, label: t('insights.window', { days: value }) })));

async function loadInsights(): Promise<void> {
    try {
        insights.value = (await axios.get<Insights>('/audience/insights', { params: { days: windowDays.value } })).data;
        insightsError.value = false;
    } catch {
        insightsError.value = true;
    }
}

onMounted(loadInsights);
watch(windowDays, loadInsights);

const growthTotals = computed(() => {
    const rows = insights.value?.growth ?? [];

    return {
        added: rows.reduce((sum, row) => sum + row.new, 0),
        lost: rows.reduce((sum, row) => sum + row.unsubscribed, 0),
    };
});

const sourceItems = computed(() => (insights.value?.sources ?? []).map((item) => ({ ...item, label: t(`insights.source.${item.key}`) })));
const engagementItems = computed(() =>
    (insights.value?.engagement ?? []).map((item) => ({
        ...item,
        label: t(`insights.tier.${item.key}`, { days: insights.value?.activeWindowDays ?? 90 }),
    })),
);

/*
 | TÖMEGES CÍMKÉZÉS: a kijelölt sorokra, vagy a keresés MINDEN találatára (a szerver egy
 | halmaz-utasítással írja, nem soronként).
 */
const selected = ref<string[]>([]);
const bulkAll = ref(false);
const bulkAction = ref('tag');
const bulkTag = ref(props.tags[0]?.uuid ?? '__new__');
const bulkNewTag = ref('');

const allOnPage = computed(() => props.contacts.items.length > 0 && props.contacts.items.every((contact) => selected.value.includes(contact.uuid)));

const tagOptions = computed(() => [
    ...props.tags.map((tag) => ({ value: tag.uuid, label: tag.name })),
    { value: '__new__', label: t('audience.bulkNewTag') },
]);

const canBulk = computed(
    () => (bulkAll.value || selected.value.length > 0) && (bulkTag.value !== '__new__' || bulkNewTag.value.trim() !== ''),
);

function toggleRow(uuid: string): void {
    selected.value = selected.value.includes(uuid) ? selected.value.filter((item) => item !== uuid) : [...selected.value, uuid];
}

function togglePage(): void {
    selected.value = allOnPage.value ? [] : props.contacts.items.map((contact) => contact.uuid);
}

function applyBulk(): void {
    if (!canBulk.value) return;

    router.post(
        '/audience/contacts/bulk-tags',
        {
            action: bulkAction.value,
            tagUuid: bulkTag.value === '__new__' ? null : bulkTag.value,
            tagName: bulkTag.value === '__new__' ? bulkNewTag.value.trim() : null,
            target: bulkAll.value ? { type: 'search', search: props.search } : { type: 'contacts', uuids: selected.value },
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                bulkAll.value = false;
                bulkNewTag.value = '';
            },
        },
    );
}

/* MEZŐKEZELŐ (review B1): a preference-center láthatóság mezőnként, alapból kikapcsolva. */
function setPreferenceVisible(uuid: string, visible: boolean): void {
    router.post(`/audience/fields/${uuid}/preference`, { visible }, { preserveScroll: true });
}

/*
 | LISTÁK / SZEGMENSEK lapozása és keresése — csak az érintett prop töltődik újra (`only`), a
 | kontaktlista kurzora és a másik blokk állapota marad (a többi query-paraméter megőrizve).
 */
const listSearch = ref(props.lists.search);
const segmentSearch = ref(props.segments.search);

function reloadBlock(prefix: 'lists' | 'segments', page: number, term: string): void {
    const query = Object.fromEntries(new URLSearchParams(window.location.search).entries());

    query[`${prefix}_page`] = String(page);
    query[`${prefix}_search`] = term.trim();

    if (page <= 1) delete query[`${prefix}_page`];
    if (!query[`${prefix}_search`]) delete query[`${prefix}_search`];

    router.get('/audience', query, { only: [prefix], preserveState: true, preserveScroll: true, replace: true });
}

function applySearch(): void {
    router.get('/audience', { search: search.value }, { preserveState: true, preserveScroll: true });
}

function startListEdit(list: { uuid: string; name: string; description: string | null; fromEmail: string | null; fromName: string | null }): void {
    editingList.value = list.uuid;
    listForm.value = {
        name: list.name,
        description: list.description ?? '',
        fromEmail: list.fromEmail ?? '',
        fromName: list.fromName ?? '',
    };
}

function saveList(uuid: string): void {
    router.post(
        `/audience/lists/${uuid}`,
        {
            name: listForm.value.name.trim(),
            description: listForm.value.description.trim() || null,
            fromEmail: listForm.value.fromEmail.trim() || null,
            fromName: listForm.value.fromName.trim() || null,
        },
        { preserveScroll: true, onSuccess: () => (editingList.value = null) },
    );
}

function createList(): void {
    if (!listName.value.trim()) return;

    router.post('/audience/lists', { name: listName.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => {
            listName.value = '';
            // Az új lista a legfrissebb: az első, szűretlen lapon látszik.
            if (props.lists.page > 1 || props.lists.search) {
                listSearch.value = '';
                reloadBlock('lists', 1, '');
            }
        },
    });
}
</script>

<template>
    <AppLayout>
        <header class="vm-audience-head">
            <div class="vm-audience-head__text">
                <p class="vm-audience-head__eyebrow">{{ t('insights.eyebrow') }}</p>
                <h1 class="vt-num">{{ t('audience.title') }}</h1>
            </div>
            <div class="vm-audience-head__actions">
                <VtButton variant="ghost" data-testid="audience-segments" @click="router.visit('/audience/segments')">
                    <VmIcon name="filter" /> {{ t('audience.segmentBuilder') }}
                </VtButton>
                <VtButton variant="ghost" data-testid="audience-tags" @click="router.visit('/audience/tags')">
                    <VmIcon name="tag" /> {{ t('audience.manageTags') }}
                </VtButton>
                <VtButton data-testid="audience-import" @click="router.visit('/audience/import')">{{ t('audience.importAction') }}</VtButton>
            </div>
        </header>

        <VtBanner v-if="insightsError" tone="warning" role="status" data-testid="insights-error">{{ t('insights.failed') }}</VtBanner>

        <section v-if="insights" class="vm-insights" data-testid="audience-insights" :aria-label="t('insights.title')">
            <div class="vm-insights__stats">
                <VmStat :label="t('insights.total')" :value="format.number(insights.totals.total)" :hint="t('insights.addedInWindow', { count: growthTotals.added, days: insights.days })" />
                <VmStat :label="t('insights.subscribed')" :value="format.number(insights.totals.subscribed)" tone="success" />
                <VmStat :label="t('insights.pending')" :value="format.number(insights.totals.pending)" :hint="t('insights.pendingHint')" :tone="insights.totals.pending ? 'warning' : 'neutral'" />
                <VmStat
                    :label="t('insights.unsubscribed')"
                    :value="format.number((insights.totals.unsubscribed + insights.totals.cleaned))"
                    :hint="t('insights.lostInWindow', { count: growthTotals.lost, days: insights.days })"
                />
            </div>

            <div class="vm-insights__grid">
                <VmCard class="vm-insights__growth">
                    <div class="vm-insights__head">
                        <h2>{{ t('insights.growth') }}</h2>
                        <div class="vm-insights__window" data-testid="insights-window">
                            <VtSegmented v-model="windowDays" :options="windowOptions" :label="t('insights.windowLabel')" />
                        </div>
                    </div>
                    <div class="vm-insights__legend">
                        <VtBadge tone="accent">{{ t('insights.new') }}: {{ growthTotals.added }}</VtBadge>
                        <VtBadge tone="danger">{{ t('insights.unsubscribed') }}: {{ growthTotals.lost }}</VtBadge>
                        <VtBadge>{{ t('insights.net') }}: {{ growthTotals.added - growthTotals.lost }}</VtBadge>
                    </div>
                    <VmGrowthBars :rows="insights.growth" />
                </VmCard>

                <div class="vm-insights__side">
                    <VmCard>
                        <h2>{{ t('insights.sources') }}</h2>
                        <VmBarList :items="sourceItems" testid="insights-sources" />
                    </VmCard>
                    <VmCard>
                        <h2>{{ t('insights.engagement') }}</h2>
                        <VmBarList :items="engagementItems" testid="insights-engagement" />
                    </VmCard>
                </div>
            </div>
        </section>

        <VtBanner v-if="unconfirmed" tone="warning" role="status" data-testid="audience-unconfirmed">
            {{ t('audience.unconfirmedWarning', { count: unconfirmed }) }}
        </VtBanner>

        <VmCard>
            <div class="vm-audience-card-head">
                <h2>{{ t('audience.lists') }}</h2>
                <form class="vm-audience-block-search" role="search" @submit.prevent="reloadBlock('lists', 1, listSearch)">
                    <VtInput v-model="listSearch" :aria-label="t('audience.listSearch')" :placeholder="t('audience.listSearch')" data-testid="lists-search" />
                    <VtButton type="submit" variant="ghost" size="sm" data-testid="lists-search-submit"><VmIcon name="search" /> {{ t('audience.searchAction') }}</VtButton>
                </form>
            </div>

            <div v-for="list in lists.items" :key="list.uuid" class="vm-stack" :data-testid="`list-${list.uuid}`">
                <div class="vm-cluster">
                    <strong>{{ list.name }}</strong>
                    <span>{{ t('audience.contactCount', { count: format.number(list.contacts) }) }}</span>
                    <span v-if="list.fromEmail" class="vt-mono">{{ list.fromEmail }}</span>

                    <VtButton variant="ghost" :data-testid="`list-edit-${list.uuid}`" @click="startListEdit(list)">
                        {{ t('audience.editList') }}
                    </VtButton>
                </div>

                <VmForm
                    v-if="editingList === list.uuid"
                    :data-testid="`list-form-${list.uuid}`"
                    @submit.prevent="saveList(list.uuid)"
                >
                    <VmFormSection>
                        <VtField :label="t('audience.listName')" :error="pageErrors.name">
                            <VtInput v-model="listForm.name" :data-testid="`list-edit-name-${list.uuid}`" />
                        </VtField>

                        <VtField :label="t('audience.listDescription')" :hint="t('audience.listDescriptionHint')">
                            <VtInput v-model="listForm.description" :data-testid="`list-edit-description-${list.uuid}`" />
                        </VtField>

                        <VtField :label="t('audience.listFromEmail')">
                            <VtInput v-model="listForm.fromEmail" :data-testid="`list-edit-from-${list.uuid}`" />
                        </VtField>
                    </VmFormSection>

                    <VmFormActions>
                        <VtButton
                            :disabled="!listForm.name.trim()"
                            type="submit"
                            :data-testid="`list-save-${list.uuid}`"
                        >
                            {{ t('audience.saveList') }}
                        </VtButton>
                    </VmFormActions>
                </VmForm>
            </div>

            <p v-if="!lists.items.length" data-testid="lists-empty">{{ lists.search ? t('audience.noMatch') : t('audience.noLists') }}</p>

            <VmPager :page="lists.page" :last="lists.last" :total="lists.total" testid="lists-pager" @change="reloadBlock('lists', $event, lists.search)" />

            <VmForm @submit.prevent="createList">
                <VmFormSection>
                    <VtField :label="t('audience.newList')" :error="editingList ? undefined : pageErrors.name">
                        <VtInput v-model="listName" data-testid="list-name" placeholder="Hírlevél" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!listName.trim()" data-testid="list-submit">
                        {{ t('audience.createList') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard data-testid="custom-fields">
            <h2>{{ t('audience.customFields') }}</h2>
            <p class="vm-audience-hint">{{ t('audience.customFieldsHint') }}</p>
            <p v-if="!fields.length">{{ t('audience.noCustomFields') }}</p>
            <ul v-else class="vm-audience-fields">
                <li v-for="field in fields" :key="field.uuid" :data-testid="`field-${field.key}`">
                    <span class="vm-truncate" :title="field.label"><strong>{{ field.label }}</strong> <code>{{ field.key }}</code> · {{ code('fieldType', field.type) }}</span>
                    <VtCheckbox
                        :model-value="field.preferenceVisible"
                        :data-testid="`field-preference-${field.key}`"
                        @update:model-value="setPreferenceVisible(field.uuid, $event)"
                    >
                        {{ t('audience.preferenceVisible') }}
                    </VtCheckbox>
                </li>
            </ul>
        </VmCard>

        <VmCard>
            <div class="vm-audience-card-head">
                <h2>{{ t('audience.segments') }}</h2>
                <Link href="/audience/segments/new" data-testid="segment-build-link">{{ t('audience.newSegment') }}</Link>
            </div>

            <form class="vm-audience-block-search" role="search" @submit.prevent="reloadBlock('segments', 1, segmentSearch)">
                <VtInput v-model="segmentSearch" :aria-label="t('audience.segmentSearch')" :placeholder="t('audience.segmentSearch')" data-testid="segments-search" />
                <VtButton type="submit" variant="ghost" size="sm" data-testid="segments-search-submit"><VmIcon name="search" /> {{ t('audience.searchAction') }}</VtButton>
            </form>

            <p v-if="!segments.items.length" data-testid="segments-empty">{{ segments.search ? t('audience.noMatch') : t('audience.noSegments') }}</p>

            <div v-for="segment in segments.items" :key="segment.uuid" class="vm-cluster" :data-testid="`segment-${segment.uuid}`">
                <Link :href="`/audience/segments/${segment.uuid}`"><strong>{{ segment.name }}</strong></Link>
                <!-- A MÉRET BECSÜLT, és a számítás ideje is látszik: a szegmens definíció
                     szerint él, a mérete azonnal elavul. -->
                <span>
                    {{ segment.estimatedSize === null ? t('audience.notEstimated') : format.number(segment.estimatedSize) }}
                    <template v-if="segment.estimatedAt">
                        ({{ format.date(segment.estimatedAt) }})
                    </template>
                </span>
            </div>

            <VmPager :page="segments.page" :last="segments.last" :total="segments.total" testid="segments-pager" @change="reloadBlock('segments', $event, segments.search)" />
        </VmCard>

        <VmCard>
            <div class="vm-audience-card-head">
                <h2>{{ t('audience.contacts') }}</h2>
                <VtButton variant="ghost" size="sm" :disabled="!contacts.items.length" data-testid="contact-select-page" @click="togglePage">
                    {{ allOnPage ? t('audience.unselectPage') : t('audience.selectPage') }}
                </VtButton>
            </div>

            <VmForm @submit.prevent="applySearch">
                <VmFormSection>
                    <VtField :label="t('audience.search')">
                        <VtInput v-model="search" data-testid="contact-search" placeholder="pelda.hu" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" data-testid="contact-search-submit">{{ t('audience.searchAction') }}</VtButton>
                </VmFormActions>
            </VmForm>

            <div class="vm-bulk" data-testid="bulk-bar" role="group" :aria-label="t('audience.bulkTitle')">
                <span class="vm-bulk__count" data-testid="bulk-selected">
                    {{ bulkAll ? t('audience.bulkAllMatching') : t('audience.bulkSelected', { count: selected.length }) }}
                </span>
                <VtCheckbox v-model="bulkAll" data-testid="bulk-all">{{ t('audience.bulkAll') }}</VtCheckbox>
                <label class="vm-visually-hidden" for="bulk-action-el">{{ t('audience.bulkActionLabel') }}</label>
                <VtSelect
                    id="bulk-action-el"
                    v-model="bulkAction"
                    :options="[
                        { value: 'tag', label: t('audience.bulkTag') },
                        { value: 'untag', label: t('audience.bulkUntag') },
                    ]"
                    data-testid="bulk-action"
                />
                <label class="vm-visually-hidden" for="bulk-tag-el">{{ t('audience.bulkTagLabel') }}</label>
                <VtSelect id="bulk-tag-el" v-model="bulkTag" :options="tagOptions" data-testid="bulk-tag" />
                <VtInput
                    v-if="bulkTag === '__new__'"
                    v-model="bulkNewTag"
                    :placeholder="t('audience.bulkNewTagPlaceholder')"
                    :aria-label="t('audience.bulkNewTag')"
                    data-testid="bulk-new-tag"
                />
                <VtButton :disabled="!canBulk" data-testid="bulk-apply" @click="applyBulk">{{ t('audience.bulkApply') }}</VtButton>
            </div>

            <p v-if="!rows.length" data-testid="contacts-empty">{{ t('audience.noContacts') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('audience.contacts')" data-testid="contacts-table">
                <!-- Az e-mail az ADATLAPRA linkel: mezők, listák, hozzájárulás-történet (import-forrással). -->
                <template #cell="{ column, row, value }">
                    <VtCheckbox
                        v-if="column.key === 'select'"
                        :model-value="selected.includes(String(row.uuid))"
                        :aria-label="t('audience.selectContact', { email: row.email })"
                        :data-testid="`contact-select-${row.email}`"
                        @update:model-value="toggleRow(String(row.uuid))"
                    />
                    <Link
                        v-else-if="column.key === 'email'"
                        :href="`/audience/contacts/${row.uuid}`"
                        class="vm-truncate"
                        :title="String(value)"
                        data-testid="contact-link"
                    >{{ value }}</Link>
                    <!-- A `data-status` / `data-consent` a GÉPI kód (nyelvfüggetlen tesztekhez), a felirat a fordítás. -->
                    <span v-else-if="column.key === 'status'" class="vm-truncate" :data-status="row.statusCode">{{ value }}</span>
                    <span v-else-if="column.key === 'consent'" class="vm-truncate" :data-consent="row.consentCode">{{ value }}</span>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-audience-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-audience-head h1 {
    margin: 0;
}

.vm-audience-head__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-audience-head__eyebrow {
    margin: 0;
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    font-weight: var(--vt-weight-semibold);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
}

.vm-audience-head__actions,
.vm-audience-card-head,
.vm-insights__head,
.vm-insights__legend,
.vm-bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vm-audience-card-head,
.vm-insights__head {
    justify-content: space-between;
}

.vm-audience-card-head h2,
.vm-insights__head h2 {
    margin: 0;
}

/* A lista- és szegmenskeresés: a mező kitölti a sort, a gomb mellette (keskenyen tördel). */
.vm-audience-block-search {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-audience-block-search > :first-child {
    flex: 1 1 calc(calc(var(--vt-space-12) * 2) * 2);
    min-width: 0;
}

.vm-insights__window {
    max-inline-size: 100%;
    overflow-x: auto;
}

.vm-insights {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-6);
}

.vm-insights__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(calc(calc(var(--vt-space-8) * 4) * 1.5), 1fr));
    gap: var(--vt-space-4);
}

.vm-insights__grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-6);
}

.vm-insights__growth {
    flex: 2 1 calc(calc(var(--vt-space-8) * 4) * 3);
}

.vm-insights__side {
    display: flex;
    flex: 1 1 calc(calc(var(--vt-space-8) * 4) * 2);
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-audience-hint {
    margin: 0;
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}

.vm-audience-fields {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-audience-fields li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-bulk {
    padding: var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-surface-2);
}

.vm-bulk__count {
    font-weight: var(--vt-weight-semibold);
}

.vm-bulk > :deep(*) {
    flex: 0 1 calc(calc(var(--vt-space-8) * 4) * 1.5);
    min-width: 0;
}

.vm-bulk > .vm-bulk__count,
.vm-bulk > :deep(button) {
    flex: 0 0 auto;
}
</style>
