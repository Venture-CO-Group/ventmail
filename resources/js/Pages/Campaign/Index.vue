<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { VtButton, VtChip, VtEmptyState, VtField, VtInput, VtPageHeader, type VtColumn } from '@venture-co-group/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import VmStatusBadge from '../../Components/Studio/VmStatusBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * KAMPÁNYLISTA (v1.1 · 5. modul): állapot-jelvények, szűrők, keresés, sor-statisztika.
 *
 * Az állapot az állapotgépből jön (a `failed` a szerver származtatott jelzése: lezárt, de
 * egyetlen levél sem ment ki). A nyitás/kattintás aránya a kézbesítettekre vetített egyedi
 * szám — ugyanaz, mint az analitika-riportban.
 */
interface CampaignRow {
    uuid: string;
    name: string;
    subject: string;
    status: string;
    displayStatus: string;
    template: string | null;
    scheduledAt: string | null;
    startedAt: string | null;
    timezone: string | null;
    editable: boolean;
    variants: number;
    recipients: number;
    sent: number;
    openRate: number | null;
    clickRate: number | null;
    progress: number;
}

const props = defineProps<{
    campaigns: CampaignRow[];
    filters: { status: string; search: string };
    pagination: { current: number; last: number; total: number; prev: string | null; next: string | null };
}>();

const { t } = useI18n();
const format = useFormat();

// Az áttekintés „Új kampány" gombja `?new=1`-gyel érkezik: a létrehozó űrlap rögtön nyitva.
const creating = ref(typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('new'));
const name = ref('');
const subject = ref('');
const search = ref(props.filters.search);

const FILTERS = ['all', 'draft', 'scheduled', 'sending', 'paused', 'sent', 'canceled', 'failed'];

type Row = Record<string, unknown> & { uuid: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('campaigns.name') },
    { key: 'status', label: t('campaigns.status') },
    { key: 'recipients', label: t('campaigns.recipients'), align: 'right' },
    { key: 'sent', label: t('campaigns.sentColumn'), align: 'right' },
    { key: 'when', label: t('campaigns.when') },
    { key: 'open', label: t('campaigns.openRate'), align: 'right' },
    { key: 'click', label: t('campaigns.clickRate'), align: 'right' },
];

const dash = '—';
const percent = (value: number | null): string => (value === null ? dash : `${format.number(value)}%`);
const when = (campaign: CampaignRow): string => {
    const value = campaign.status === 'scheduled' ? campaign.scheduledAt : campaign.startedAt;

    return value ? format.dateTime(value) : dash;
};

const rows = computed<Row[]>(() =>
    props.campaigns.map((campaign) => ({
        uuid: campaign.uuid,
        name: campaign.name,
        status: campaign.displayStatus,
        recipients: campaign.recipients ? format.number(campaign.recipients) : dash,
        sent: campaign.sent ? format.number(campaign.sent) : dash,
        when: when(campaign),
        open: percent(campaign.openRate),
        click: percent(campaign.clickRate),
        template: campaign.template ?? '',
        progress: campaign.progress,
    })),
);

function filter(status: string): void {
    router.get('/campaigns', { status, search: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

function applySearch(): void {
    filter(props.filters.status);
}

function create(): void {
    if (!name.value.trim()) return;

    router.post('/campaigns', { name: name.value.trim(), subject: subject.value.trim() });
}
</script>

<template>
    <Head :title="t('campaigns.title')" />

    <AppLayout>
        <VtPageHeader :overline="t('nav.studio')" :title="t('campaigns.title')" :description="t('page.campaigns.lead')">
            <template #actions>
                <VtButton data-testid="campaign-new" @click="creating = !creating"><VmIcon name="plus" /> {{ t('campaigns.new') }}</VtButton>
            </template>
        </VtPageHeader>

        <VmCard v-if="creating || !campaigns.length" :title="t('campaigns.new')">
            <VmForm @submit.prevent="create">
                <VmFormSection>
                    <VmFormRow>
                        <VtField :label="t('campaigns.name')">
                            <VtInput v-model="name" data-testid="campaign-name" :placeholder="t('campaigns.namePlaceholder')" />
                        </VtField>

                        <VtField :label="t('campaigns.subject')" :hint="t('campaigns.subjectLater')">
                            <VtInput v-model="subject" data-testid="campaign-subject" />
                        </VtField>
                    </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!name.trim()" data-testid="campaign-submit">
                        {{ t('campaigns.create') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <!-- A SZŰRŐSOR (Figma Campaigns): állapot-chipek · köz · kereső. -->
        <div class="vm-camp-filters" role="group" :aria-label="t('campaigns.filterLabel')">
            <VtChip
                v-for="status in FILTERS"
                :key="status"
                :selected="filters.status === status"
                :data-testid="`campaign-filter-${status}`"
                @update:selected="() => filter(status)"
            >
                {{ t(`campaigns.filter.${status}`) }}
            </VtChip>
            <form class="vm-camp-search" role="search" @submit.prevent="applySearch">
                <VtInput v-model="search" type="search" icon="search" :placeholder="t('campaigns.search')" :aria-label="t('campaigns.search')" data-testid="campaign-search" />
                <button type="submit" class="vm-visually-hidden" data-testid="campaign-search-submit">{{ t('campaigns.search') }}</button>
            </form>
        </div>

        <VmCard :flush="rows.length > 0" data-testid="campaigns-card">

            <VtEmptyState
                v-if="!rows.length"
                :title="filters.status === 'all' && !filters.search ? t('campaigns.empty') : t('campaigns.noMatch')"
                icon="send"
                data-testid="campaigns-empty"
            />
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('campaigns.list')" data-testid="campaigns-table">
                <template #cell="{ column, row, value }">
                    <span v-if="column.key === 'name'" class="vm-camp-name">
                        <Link :href="`/campaigns/${row.uuid}`" class="vm-truncate" :title="String(value)" :data-testid="`open-${row.uuid}`">{{ value }}</Link>
                        <small v-if="row.template" class="vm-truncate" :title="String(row.template)">{{ row.template }}</small>
                    </span>
                    <span v-else-if="column.key === 'status'" class="vm-camp-status">
                        <VmStatusBadge :status="String(value)" :testid="`campaign-row-status-${row.uuid}`" />
                        <span
                            v-if="value === 'sending'"
                            class="vm-camp-progress"
                            role="progressbar"
                            :aria-valuenow="Number(row.progress)"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="t('campaigns.progress')"
                        ><span :style="{ inlineSize: `${row.progress}%` }" /></span>
                    </span>
                    <span v-else class="vm-truncate" :class="{ 'vm-truncate--end': column.align === 'right' }" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>

            <template v-if="pagination.last > 1" #footer>
                <nav class="vm-camp-pages" :aria-label="t('campaigns.pages')">
                    <span>{{ t('campaigns.page', { current: pagination.current, last: pagination.last, total: pagination.total }) }}</span>
                    <VtButton variant="ghost" size="sm" :disabled="!pagination.prev" data-testid="campaign-prev" @click="pagination.prev && router.visit(pagination.prev)">‹</VtButton>
                    <VtButton variant="ghost" size="sm" :disabled="!pagination.next" data-testid="campaign-next" @click="pagination.next && router.visit(pagination.next)">›</VtButton>
                </nav>
            </template>
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-camp-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2);
}

.vm-camp-search {
    margin-inline-start: auto;
    inline-size: calc(var(--vt-space-12) * 6 - var(--vt-space-6));
    max-inline-size: 100%;
}

.vm-camp-search > .vt-input {
    inline-size: 100%;
}

.vm-camp-name {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-camp-name small {
    color: var(--vt-text-tertiary);
}

.vm-camp-status {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
}

.vm-camp-progress {
    display: block;
    inline-size: calc(calc(var(--vt-space-12) * 2) + var(--vt-space-4));
    block-size: var(--vt-space-1);
    border-radius: var(--vt-radius-full);
    background-color: var(--vt-surface-2);
    overflow: hidden;
}

.vm-camp-progress span {
    display: block;
    block-size: 100%;
    background-image: var(--vm-glow-linear);
}

.vm-camp-pages {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--vt-space-2);
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}
</style>
