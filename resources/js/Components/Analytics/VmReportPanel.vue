<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { VtBadge, VtButton, VtCheckbox, VtField, VtInput, VtSelect, type VtBadgeTone } from '@venture/ui';
import axios, { AxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../Form/VmCard.vue';
import VmIcon from '../Shell/VmIcon.vue';
import type { ReportRow } from './types';
import VmCoverPreview from './VmCoverPreview.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * BRANDELT PDF-RIPORT (v1.1 · 8. modul) — kérés (kampány vagy időszak, szekciók), a háttér-
 * feladat követése (3 mp-es lekérdezés, legfeljebb 2 percig), letöltés a KÉRÉSRE generált, rövid
 * életű aláírt linkkel. A borító-előnézet a workspace brandingjét mutatja.
 */
const props = defineProps<{
    campaigns: Array<{ uuid: string; name: string }>;
    selectedCampaign: string | null;
    from: string;
    to: string;
    today: string;
    branding: { color: string | null; coverText: 'light' | 'dark' | 'default'; companyName: string; footerText: string; logoUrl: string | null };
    canManageBranding: boolean;
    dateRange: string;
}>();

const { t } = useI18n();
const format = useFormat();

const SECTIONS = ['kpis', 'charts', 'top_links', 'journeys', 'deliverability'] as const;

const form = reactive({
    subject: props.selectedCampaign ? `campaign:${props.selectedCampaign}` : 'period',
    from: props.from,
    to: props.to,
    sections: { kpis: true, charts: true, top_links: true, journeys: false, deliverability: false } as Record<string, boolean>,
});

const reports = ref<ReportRow[]>([]);
const busy = ref(false);
const error = ref<string | null>(null);
let timer: number | undefined;
let pollUntil = 0;

const subjectOptions = computed(() => [
    ...props.campaigns.map((campaign) => ({ value: `campaign:${campaign.uuid}`, label: t('analytics.report.kindCampaign', { name: campaign.name }) })),
    { value: 'period', label: t('analytics.report.kindPeriod') },
]);

const isPeriod = computed(() => form.subject === 'period');
const pending = computed(() => reports.value.some((row) => row.status === 'queued' || row.status === 'rendering'));
const latest = computed(() => reports.value[0] ?? null);

const tones: Record<ReportRow['status'], VtBadgeTone> = { queued: 'info', rendering: 'info', ready: 'success', failed: 'danger', expired: 'neutral' };

async function refresh(): Promise<void> {
    try {
        const response = await axios.get<{ reports: ReportRow[] }>('/analytics/reports', { headers: { Accept: 'application/json' } });
        reports.value = response.data.reports;
    } catch {
        // A lista frissítése nem kritikus: a következő kör újrapróbálja.
    }

    schedule();
}

function schedule(): void {
    window.clearTimeout(timer);

    if (pending.value && Date.now() < pollUntil) {
        timer = window.setTimeout(() => void refresh(), 3000);
    }
}

async function create(): Promise<void> {
    busy.value = true;
    error.value = null;

    const sections = SECTIONS.filter((key) => form.sections[key]);
    const payload: Record<string, unknown> = isPeriod.value
        ? { kind: 'period', from: form.from, to: form.to, sections }
        : { kind: 'campaign', campaignUuid: form.subject.slice('campaign:'.length), sections };

    try {
        await axios.post('/analytics/reports', payload, { headers: { Accept: 'application/json' } });
        pollUntil = Date.now() + 120000;
        await refresh();
    } catch (exception) {
        const response = (exception as AxiosError<{ message?: string }>).response;
        error.value = response?.status === 429 ? t('analytics.report.throttled') : (response?.data?.message ?? t('analytics.report.failedGeneric'));
    } finally {
        busy.value = false;
    }
}

/*
 | A letöltő-link 10 percig érvényes, a lista lekérdezése viszont 2 perc után leáll (review NIT):
 | kattintáskor FRISS aláírt linket kérünk, és azzal indul a letöltés.
 */
async function download(uuid: string): Promise<void> {
    await refresh();
    const fresh = reports.value.find((row) => row.uuid === uuid)?.downloadUrl;

    if (fresh) window.location.assign(fresh);
}

const formatSize = (bytes: number | null) => (bytes === null ? '' : `${format.number(Math.max(1, Math.round(bytes / 1024)))} KB`);
const formatDate = (iso: string | null) => (iso ? format.dateTime(iso) : '');

onMounted(() => void refresh());
onBeforeUnmount(() => window.clearTimeout(timer));
</script>

<template>
    <VmCard glass>
        <section id="report" class="vm-report" data-testid="report-panel" aria-labelledby="report-title">
            <header class="vm-report__head">
                <h2 id="report-title" class="vm-report__title">{{ t('analytics.report.title') }}</h2>
                <VtBadge v-if="latest" :tone="tones[latest.status]" size="sm" data-testid="report-status">{{ t(`analytics.report.status.${latest.status}`) }}</VtBadge>
            </header>

            <div class="vm-report__grid">
                <form class="vm-report__form" novalidate @submit.prevent="create">
                    <div class="vm-report__row">
                        <VtField :label="t('analytics.report.subject')">
                            <VtSelect v-model="form.subject" :options="subjectOptions" data-testid="report-subject" />
                        </VtField>
                        <div v-if="isPeriod" class="vm-report__dates">
                            <VtField :label="t('analytics.range.from')">
                                <VtInput v-model="form.from" type="date" :max="today" data-testid="report-from" />
                            </VtField>
                            <VtField :label="t('analytics.range.to')">
                                <VtInput v-model="form.to" type="date" :max="today" data-testid="report-to" />
                            </VtField>
                        </div>
                    </div>

                    <fieldset class="vm-report__sections">
                        <legend>{{ t('analytics.report.content') }}</legend>
                        <VtCheckbox v-for="key in SECTIONS" :key="key" v-model="form.sections[key]" :data-testid="`report-section-${key}`">
                            {{ t(`analytics.report.sections.${key}`) }}
                        </VtCheckbox>
                    </fieldset>

                    <p class="vm-banner vm-report__hint">
                        <VmIcon name="info" />
                        <span>
                            {{ t('analytics.report.brandingHint') }}
                            <Link v-if="canManageBranding" href="/settings/branding" data-testid="report-branding-link">{{ t('analytics.report.brandingLink') }}</Link>
                        </span>
                    </p>

                    <p v-if="error" class="vm-banner vm-banner--danger" role="alert" data-testid="report-error">{{ error }}</p>

                    <div class="vm-report__actions">
                        <VtButton type="submit" :loading="busy" :disabled="busy || !SECTIONS.some((key) => form.sections[key])" data-testid="report-create">
                            <VmIcon name="file" /> {{ t('analytics.report.create') }}
                        </VtButton>
                        <a
                            v-if="latest?.downloadUrl"
                            :href="latest.downloadUrl"
                            class="vm-report__download"
                            download
                            data-testid="report-download"
                            @click.prevent="download(latest.uuid)"
                        >
                            <VmIcon name="download" /> {{ t('analytics.report.download') }} · {{ formatSize(latest.size) }}
                        </a>
                    </div>

                    <div class="vm-report__list">
                        <h3 class="vm-report__subtitle">{{ t('analytics.report.list') }}</h3>
                        <p v-if="!reports.length" class="vm-report__muted" data-testid="report-none">{{ t('analytics.report.none') }}</p>
                        <ul v-else class="vm-report__rows">
                            <li v-for="row in reports.slice(0, 5)" :key="row.uuid" class="vm-report__item" :data-testid="`report-row-${row.status}`">
                                <VtBadge :tone="tones[row.status]" size="sm">{{ t(`analytics.report.status.${row.status}`) }}</VtBadge>
                                <span class="vm-truncate">{{ row.kind === 'campaign' ? t('analytics.report.kindCampaignShort') : `${format.day(row.from ?? "")} – ${format.day(row.to ?? "")}` }}</span>
                                <span class="vm-report__muted">{{ formatDate(row.createdAt) }}</span>
                                <span v-if="row.status === 'failed'" class="vm-report__muted">{{ t('analytics.report.failed', { code: row.failureCode ?? '' }) }}</span>
                                <a v-if="row.downloadUrl" :href="row.downloadUrl" download class="vm-report__link" @click.prevent="download(row.uuid)">{{ t('analytics.report.download') }}</a>
                            </li>
                        </ul>
                    </div>
                </form>

                <VmCoverPreview
                    :color="branding.color"
                    :cover-text="branding.coverText"
                    :company="branding.companyName"
                    :footer="branding.footerText"
                    :logo-url="branding.logoUrl"
                    :title="isPeriod ? t('analytics.report.coverPeriod') : t('analytics.report.coverCampaign')"
                    :subtitle="isPeriod ? branding.companyName : (campaigns.find((c) => `campaign:${c.uuid}` === form.subject)?.name ?? '')"
                    :date-range="isPeriod ? `${format.day(form.from)} – ${format.day(form.to)}` : dateRange"
                    testid="report-cover-preview"
                />
            </div>
        </section>
    </VmCard>
</template>

<style scoped>
.vm-report {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-report__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
}

.vm-report__title {
    margin: 0;
    font-family: var(--vt-font-display);
    font-size: var(--vt-fs-h5);
}

.vm-report__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 3)), 1fr));
    gap: var(--vt-space-8);
    align-items: start;
}

.vm-report__form {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-report__row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 1.6)), 1fr));
    gap: var(--vt-space-4);
}

.vm-report__dates {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 1.1)), 1fr));
    gap: var(--vt-space-3);
}

.vm-report__sections {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 1.6)), 1fr));
    gap: var(--vt-space-3);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.vm-report__sections legend {
    margin-bottom: var(--vt-space-2);
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-report__hint {
    display: flex;
    gap: var(--vt-space-2);
    align-items: flex-start;
}

.vm-report__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-3);
    align-items: center;
}

.vm-report__download,
.vm-report__link {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-2);
    color: var(--vt-accent);
}

.vm-report__list {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
}

.vm-report__subtitle {
    margin: 0;
    font-size: var(--vt-fs-body);
}

.vm-report__rows {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-report__item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-report__muted {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}
</style>
