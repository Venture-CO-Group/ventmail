<script setup lang="ts">
import { Deferred, router } from '@inertiajs/vue3';
import { VtButton, VtInput, VtSegmented, VtSelect } from '@venture/ui';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AudienceSection, CampaignSection, JourneySection, PlatformSection } from '../../Components/Analytics/types';
import VmHeatmap from '../../Components/Analytics/VmHeatmap.vue';
import VmReportPanel from '../../Components/Analytics/VmReportPanel.vue';
import VmBarChart from '../../Components/Charts/VmBarChart.vue';
import VmChartCard from '../../Components/Charts/VmChartCard.vue';
import VmDonutChart from '../../Components/Charts/VmDonutChart.vue';
import VmFunnelBar from '../../Components/Charts/VmFunnelBar.vue';
import VmLineChart from '../../Components/Charts/VmLineChart.vue';
import { CATEGORY_TOKENS } from '../../Components/Charts/chartTheme';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * ANALITIKA (v1.1 · 8. modul) — kampány, közönség, journey és platform egy oldalon, minden
 * grafikon-kártyán PNG-exporttal, alul a brandelt PDF-riporttal.
 *
 * A szekciók HALASZTOTT propként érkeznek (`Inertia::defer`): a váz azonnal kirajzolódik, a
 * drágább aggregátumok párhuzamosan töltődnek. A NYITÁS torzít (Apple MPP): a kártya ezt kimondja.
 */
const props = defineProps<{
    campaigns: Array<{ uuid: string; name: string; status: string }>;
    journeys: Array<{ uuid: string; name: string }>;
    selection: { campaign: string | null; journey: string | null; range: string; from: string; to: string; days: number; timezone: string; today: string };
    canManageBranding: boolean;
    branding: { color: string | null; coverText: 'light' | 'dark' | 'default'; companyName: string; footerText: string; logoUrl: string | null };
    campaignSection?: CampaignSection | null;
    audienceSection?: AudienceSection;
    journeySection?: JourneySection | null;
    platformSection?: PlatformSection;
}>();

const { t, locale } = useI18n();

const custom = reactive({ from: props.selection.from, to: props.selection.to });
const showCustom = ref(props.selection.range === 'custom');

const rangeOptions = computed(() => [
    { value: '7', label: t('analytics.range.d7') },
    { value: '30', label: t('analytics.range.d30') },
    { value: '90', label: t('analytics.range.d90') },
    { value: 'custom', label: t('analytics.range.custom') },
]);
const campaignOptions = computed(() =>
    props.campaigns.length ? props.campaigns.map((c) => ({ value: c.uuid, label: c.name })) : [{ value: '', label: t('analytics.noCampaign') }],
);
const journeyOptions = computed(() =>
    props.journeys.length ? props.journeys.map((j) => ({ value: j.uuid, label: j.name })) : [{ value: '', label: t('analytics.noJourney') }],
);

function go(patch: Record<string, string | null>): void {
    const query: Record<string, string> = {};
    const next = { range: props.selection.range, from: props.selection.from, to: props.selection.to, campaign: props.selection.campaign, journey: props.selection.journey, ...patch };

    query.range = String(next.range);
    if (next.range === 'custom') {
        query.from = String(next.from);
        query.to = String(next.to);
    }
    if (next.campaign) query.campaign = next.campaign;
    if (next.journey) query.journey = next.journey;

    router.get('/analytics', query, { preserveScroll: true });
}

function onRange(value: string): void {
    if (value === 'custom') {
        showCustom.value = true;

        return;
    }

    showCustom.value = false;
    go({ range: value });
}

const periodLabel = computed(() => `${props.selection.from} – ${props.selection.to}`);
const campaignName = computed(() => props.campaignSection?.campaign?.name ?? '');

const fmt = (value: number | undefined | null) => (value ?? 0).toLocaleString(locale.value);
const pct = (value: number | undefined | null, digits = 1) => `${(value ?? 0).toLocaleString(locale.value, { maximumFractionDigits: digits })}%`;

// --- Kampány: idősor -------------------------------------------------------------------
const timeline = computed(() => props.campaignSection?.timeline ?? { bucket: 'day' as const, points: [] });
const timelineSeries = computed(() => [
    { name: t('analytics.series.opens'), data: timeline.value.points.map((p) => p.opens), token: '--vt-color-accent-500', area: true },
    { name: t('analytics.series.clicks'), data: timeline.value.points.map((p) => p.clicks), token: '--vt-color-cyan-400', area: true },
]);
const timelineLabels = computed(() => timeline.value.points.map((p) => (timeline.value.bucket === 'hour' ? p.at.replace('T', ' ') : p.at.slice(5))));

// --- Kampány: eszköz / kliens -------------------------------------------------------------
const devices = computed(() => props.campaignSection?.devices);
const deviceItems = computed(() =>
    (devices.value?.devices ?? []).map((row, index) => ({ name: t(`analytics.device.${row.key}`), value: row.total, token: CATEGORY_TOKENS[index] })),
);
const deviceTotal = computed(() => (devices.value?.devices ?? []).reduce((sum, row) => sum + row.total, 0));
const mobileShare = computed(() => {
    const mobile = (devices.value?.devices ?? []).filter((row) => row.key === 'mobile' || row.key === 'tablet').reduce((s, r) => s + r.total, 0);

    return deviceTotal.value ? Math.round((mobile / deviceTotal.value) * 100) : 0;
});
const clientRows = computed(() => {
    const rows = devices.value?.clients ?? [];
    const total = rows.reduce((sum, row) => sum + row.total, 0);

    return rows.map((row) => ({ key: row.key, label: t(`analytics.client.${row.key}`), total: row.total, share: total ? Math.round((row.total / total) * 1000) / 10 : 0 }));
});

// --- Kampány: top linkek ------------------------------------------------------------------
const links = computed(() => props.campaignSection?.links ?? []);
const uniqueClicks = computed(() => links.value.reduce((sum, link) => sum + link.unique, 0));

// --- Közönség --------------------------------------------------------------------------------
const growth = computed(() => props.audienceSection?.growth ?? []);
const growthSeries = computed(() => [
    { name: t('analytics.series.new'), data: growth.value.map((g) => g.new), token: '--vt-color-accent-500', stack: 'g' },
    { name: t('analytics.series.unsubscribed'), data: growth.value.map((g) => -g.unsubscribed), token: '--vt-color-danger', stack: 'g' },
    { name: t('analytics.series.net'), data: growth.value.map((g) => g.net), token: '--vt-color-cyan-400', line: true },
]);
const engagementItems = computed(() =>
    (props.audienceSection?.engagement ?? []).map((row, index) => ({ name: t(`analytics.tier.${row.key}`), value: row.count, token: ['--vt-color-success', '--vt-color-warning', '--vt-color-danger', '--vt-color-neutral-400'][index] })),
);

// --- Journey ---------------------------------------------------------------------------------
const funnelRows = computed(() =>
    (props.journeySection?.rows ?? []).map((row) => ({
        name: `${'· '.repeat(Math.min(row.depth, 4))}${row.label || t(`journeyCanvas.nodes.${row.type}`)}${row.branch ? ` (${t('analytics.branch', { name: row.branch })})` : ''}`,
        value: row.entered,
        note: `${fmt(row.entered)} · ${pct(row.conversion)}`,
    })),
);

// --- Platform --------------------------------------------------------------------------------
const deliverability = computed(() => props.platformSection?.series ?? []);
const deliverabilitySeries = computed(() => [
    { name: t('analytics.series.delivered'), data: deliverability.value.map((d) => d.deliveredRate), token: '--vt-color-success', area: true },
    { name: t('analytics.series.bounced'), data: deliverability.value.map((d) => d.bounceRate), token: '--vt-color-warning' },
    { name: t('analytics.series.complained'), data: deliverability.value.map((d) => d.complaintRate), token: '--vt-color-danger' },
]);
const bounces = computed(() => props.platformSection?.bounces);
const bounceItems = computed(() => {
    const classes = bounces.value?.classes ?? {};

    return [
        ...Object.entries(classes).map(([key, value], index) => ({ key, name: t(`analytics.bounce.${key}`), value, token: CATEGORY_TOKENS[(index + 5) % CATEGORY_TOKENS.length] })),
        { key: 'unclassified', name: t('analytics.bounce.unclassified'), value: bounces.value?.unclassified ?? 0, token: '--vt-color-neutral-500' },
    ];
});
const bounceRate = computed(() => {
    const sent = props.platformSection?.sum?.sent ?? 0;

    return sent ? Math.round(((props.platformSection?.sum?.bounced ?? 0) / sent) * 10000) / 100 : 0;
});

// A kártyák exportálható példányai.
const timelineChart = ref<InstanceType<typeof VmLineChart> | null>(null);
const deviceChart = ref<InstanceType<typeof VmDonutChart> | null>(null);
const growthChart = ref<InstanceType<typeof VmBarChart> | null>(null);
const engagementChart = ref<InstanceType<typeof VmDonutChart> | null>(null);
const funnelChart = ref<InstanceType<typeof VmFunnelBar> | null>(null);
const deliverabilityChart = ref<InstanceType<typeof VmLineChart> | null>(null);
const bounceChart = ref<InstanceType<typeof VmDonutChart> | null>(null);

function scrollToReport(): void {
    document.getElementById('report')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('analytics.eyebrow')" :title="t('analytics.title')" :lead="t('analytics.lead')" testid="analytics-head">
            <div class="vm-an-filters">
                <div class="vm-seg-scroll">
                    <VtSegmented
                        :model-value="showCustom ? 'custom' : selection.range"
                        :options="rangeOptions"
                        :label="t('analytics.range.label')"
                        data-testid="analytics-range"
                        @update:model-value="onRange(String($event))"
                    />
                </div>
                <form v-if="showCustom" class="vm-an-custom" data-testid="analytics-custom" @submit.prevent="go({ range: 'custom', from: custom.from, to: custom.to })">
                    <VtInput v-model="custom.from" type="date" :max="selection.today" :aria-label="t('analytics.range.from')" />
                    <VtInput v-model="custom.to" type="date" :max="selection.today" :aria-label="t('analytics.range.to')" />
                    <VtButton type="submit" variant="glass" size="sm">{{ t('analytics.range.apply') }}</VtButton>
                </form>
                <div class="vm-an-select">
                    <VtSelect
                        :model-value="selection.campaign ?? ''"
                        :options="campaignOptions"
                        :aria-label="t('analytics.pickCampaign')"
                        data-testid="analytics-campaign"
                        @update:model-value="go({ campaign: String($event) || null })"
                    />
                </div>
                <VtButton data-testid="analytics-pdf" @click="scrollToReport"><VmIcon name="file" /> {{ t('analytics.report.button') }}</VtButton>
            </div>
        </VmPageHeader>

        <p class="vm-an-tz" data-testid="analytics-period">{{ periodLabel }} · {{ selection.timezone }}</p>

        <h2 class="vm-an-section">{{ t('analytics.section.campaign') }}<span v-if="campaignName"> · {{ campaignName }}</span></h2>

        <Deferred data="campaignSection">
            <template #fallback><div class="vm-an-grid"><div class="vm-an-skeleton" /><div class="vm-an-skeleton" /></div></template>

            <p v-if="campaignSection === null" class="vm-banner" data-testid="analytics-empty">{{ t('analytics.empty') }}</p>
            <p v-else-if="campaignSection && !campaignSection.available" class="vm-banner vm-banner--warning" data-testid="campaign-unavailable">{{ t('analytics.unavailable') }}</p>
            <template v-else-if="campaignSection">
                <div class="vm-an-grid">
                    <VmChartCard id="open-click" :title="t('analytics.chart.openClick')" :subtitle="t(`analytics.bucket.${timeline.bucket}`)" :context="campaignName" :chart="timeline.points.length ? timelineChart : null">
                        <VmLineChart v-if="timeline.points.length" ref="timelineChart" :categories="timelineLabels" :series="timelineSeries" :label="t('analytics.chart.openClick')" testid="chart-open-click" />
                        <p v-else class="vm-an-muted">{{ t('analytics.noData') }}</p>
                        <template #note>{{ t('analytics.uniqueNote') }} · {{ t('analytics.mppNote') }}</template>
                    </VmChartCard>

                    <VmChartCard id="devices" :title="t('analytics.chart.devices')" :subtitle="t('analytics.eventsNote')" :context="campaignName" :chart="devices?.hasData ? deviceChart : null">
                        <p v-if="!devices?.hasData" class="vm-an-muted" data-testid="devices-empty">{{ t('analytics.noDataBeforeV12') }}</p>
                        <div v-else class="vm-an-split">
                            <div class="vm-an-donut">
                                <VmDonutChart ref="deviceChart" :items="deviceItems" :center="`${mobileShare}%`" :center-caption="t('analytics.device.mobile')" :label="t('analytics.chart.devices')" testid="chart-devices" />
                            </div>
                            <ul class="vm-an-bars" data-testid="client-list">
                                <li v-for="row in clientRows" :key="row.key">
                                    <span class="vm-an-bars__label vm-truncate">{{ row.label }}</span>
                                    <span class="vm-an-bars__value">{{ pct(row.share) }}</span>
                                    <span class="vm-an-bars__track"><i :style="{ width: `${row.share}%` }" /></span>
                                </li>
                            </ul>
                        </div>
                        <template v-if="clientRows.some((row) => row.key === 'apple_mpp')" #note>{{ t('analytics.machineOpensNote') }}</template>
                    </VmChartCard>
                </div>

                <div class="vm-an-grid vm-an-grid--wide">
                    <VmChartCard
                        id="heatmap"
                        :title="t('analytics.chart.heatmap')"
                        :subtitle="t('analytics.clicksTotal', { count: fmt(uniqueClicks) })"
                        :csv-href="campaignSection.campaign ? `/analytics/campaigns/${campaignSection.campaign.uuid}/links.csv` : undefined"
                    >
                        <VmHeatmap v-if="campaignSection.campaign" :campaign-uuid="campaignSection.campaign.uuid" :links="links" />
                    </VmChartCard>

                    <VmChartCard id="top-links" :title="t('analytics.chart.topLinks')" :csv-href="campaignSection.campaign ? `/analytics/campaigns/${campaignSection.campaign.uuid}/links.csv` : undefined">
                        <p v-if="!links.length" class="vm-an-muted" data-testid="links-empty">{{ t('analytics.noClicks') }}</p>
                        <ul v-else class="vm-an-bars vm-an-bars--links" data-testid="top-links">
                            <li v-for="link in links.slice(0, 10)" :key="link.key">
                                <span class="vm-an-bars__label vm-truncate vt-text-mono" :title="link.url">{{ link.url.replace(/^https?:\/\//, '') }}</span>
                                <span class="vm-an-bars__value">{{ fmt(link.unique) }} · {{ pct(link.share) }}</span>
                                <span class="vm-an-bars__track"><i :style="{ width: `${link.share}%` }" /></span>
                            </li>
                        </ul>
                    </VmChartCard>
                </div>
            </template>
        </Deferred>

        <h2 class="vm-an-section">{{ t('analytics.section.audience') }} · {{ t('analytics.section.journey') }}</h2>

        <div class="vm-an-grid">
            <Deferred data="audienceSection">
                <template #fallback><div class="vm-an-skeleton" /></template>
                <VmChartCard id="growth" :title="t('analytics.chart.growth')" :context="periodLabel" :chart="audienceSection?.available ? growthChart : null">
                    <p v-if="!audienceSection?.available" class="vm-banner vm-banner--warning">{{ t('analytics.unavailable') }}</p>
                    <template v-else>
                        <VmBarChart ref="growthChart" :categories="growth.map((g) => g.date.slice(5))" :series="growthSeries" :label="t('analytics.chart.growth')" testid="chart-growth" />
                    </template>
                    <template v-if="audienceSection?.churn" #note>
                        {{ t('analytics.churn', { rate: pct(audienceSection.churn.rate), lost: fmt(audienceSection.churn.unsubscribed), base: fmt(audienceSection.churn.subscribedAtStart) }) }}
                    </template>
                </VmChartCard>
            </Deferred>

            <Deferred data="audienceSection">
                <template #fallback><div class="vm-an-skeleton" /></template>
                <VmChartCard id="engagement" :title="t('analytics.chart.engagement')" :context="periodLabel" :chart="audienceSection?.available ? engagementChart : null">
                    <div v-if="audienceSection?.available" class="vm-an-split">
                        <div class="vm-an-donut">
                            <VmDonutChart ref="engagementChart" :items="engagementItems" :label="t('analytics.chart.engagement')" testid="chart-engagement" />
                        </div>
                        <ul class="vm-an-legend">
                            <li v-for="item in engagementItems" :key="item.name"><span class="vm-truncate">{{ item.name }}</span><b>{{ fmt(item.value) }}</b></li>
                        </ul>
                    </div>
                    <template #note>{{ t('analytics.tierWindow', { days: audienceSection?.activeWindowDays ?? 90 }) }}</template>
                </VmChartCard>
            </Deferred>

            <Deferred data="journeySection">
                <template #fallback><div class="vm-an-skeleton" /></template>
                <VmChartCard id="journey" :title="t('analytics.chart.journey')" :subtitle="journeySection?.journey?.name" :context="journeySection?.journey?.name" :chart="funnelRows.length && journeySection?.nodesAvailable ? funnelChart : null">
                    <template v-if="journeys.length > 1" #legend>
                        <div class="vm-an-select">
                            <VtSelect :model-value="selection.journey ?? ''" :options="journeyOptions" :aria-label="t('analytics.pickJourney')" data-testid="analytics-journey" @update:model-value="go({ journey: String($event) || null })" />
                        </div>
                    </template>
                    <p v-if="journeySection === null" class="vm-an-muted">{{ t('analytics.noJourney') }}</p>
                    <p v-else-if="!journeySection?.available || !journeySection?.nodesAvailable" class="vm-banner vm-banner--warning" data-testid="journey-unavailable">{{ t('analytics.unavailable') }}</p>
                    <VmFunnelBar v-else-if="funnelRows.length" ref="funnelChart" :rows="funnelRows" :label="t('analytics.chart.journey')" testid="chart-journey" />
                    <p v-else class="vm-an-muted">{{ t('analytics.noData') }}</p>
                    <template v-if="journeySection?.goal" #note>
                        {{ t('analytics.goal', { rate: pct(journeySection.goal.rate), reached: fmt(journeySection.goal.reached), entered: fmt(journeySection.goal.entered) }) }}
                    </template>
                </VmChartCard>
            </Deferred>

            <Deferred data="platformSection">
                <template #fallback><div class="vm-an-skeleton" /></template>
                <VmChartCard id="bounces" :title="t('analytics.chart.bounces')" :context="periodLabel" :chart="bounces?.total ? bounceChart : null">
                    <p v-if="!platformSection?.available" class="vm-banner vm-banner--warning">{{ t('analytics.unavailable') }}</p>
                    <p v-else-if="!bounces?.total" class="vm-an-muted" data-testid="bounces-empty">{{ t('analytics.noData') }}</p>
                    <div v-else class="vm-an-split">
                        <div class="vm-an-donut">
                            <VmDonutChart ref="bounceChart" :items="bounceItems" :center="pct(bounceRate, 2)" :center-caption="t('analytics.chart.bounces')" :label="t('analytics.chart.bounces')" testid="chart-bounces" />
                        </div>
                        <ul class="vm-an-legend" data-testid="bounce-legend">
                            <li v-for="item in bounceItems" :key="item.key"><span class="vm-truncate">{{ item.name }}</span><b>{{ fmt(item.value) }}</b></li>
                        </ul>
                    </div>
                </VmChartCard>
            </Deferred>
        </div>

        <h2 class="vm-an-section">{{ t('analytics.section.platform') }}</h2>

        <Deferred data="platformSection">
            <template #fallback><div class="vm-an-skeleton" /></template>
            <VmChartCard id="deliverability" :title="t('analytics.chart.deliverability')" :context="periodLabel" :chart="deliverability.length ? deliverabilityChart : null">
                <p v-if="!platformSection?.available" class="vm-banner vm-banner--warning">{{ t('analytics.unavailable') }}</p>
                <VmLineChart
                    v-else-if="deliverability.length"
                    ref="deliverabilityChart"
                    :categories="deliverability.map((d) => d.day.slice(5))"
                    :series="deliverabilitySeries"
                    :label="t('analytics.chart.deliverability')"
                    percent
                    :mark-line="{ value: platformSection?.complaintThreshold ?? 0.3, name: t('analytics.series.threshold'), token: '--vt-color-danger', seriesIndex: 2 }"
                    testid="chart-deliverability"
                />
                <p v-else class="vm-an-muted">{{ t('analytics.noData') }}</p>
            </VmChartCard>
        </Deferred>

        <VmReportPanel
            :campaigns="campaigns"
            :selected-campaign="selection.campaign"
            :from="selection.from"
            :to="selection.to"
            :today="selection.today"
            :branding="branding"
            :can-manage-branding="canManageBranding"
            :date-range="periodLabel"
        />
    </AppLayout>
</template>

<style scoped>
.vm-an-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-an-custom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2);
}

.vm-an-select {
    min-width: 0;
    width: min(100%, calc(var(--vt-space-32) * 2));
}

.vm-an-tz {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
}

.vm-an-section {
    margin: var(--vt-space-4) 0 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
    font-weight: var(--vt-weight-semibold);
    letter-spacing: var(--vt-tracking-wide);
    text-transform: uppercase;
    overflow-wrap: anywhere;
}

.vm-an-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 3.5)), 1fr));
    gap: var(--vt-space-6);
    align-items: start;
    min-width: 0;
}

.vm-an-grid--wide {
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 3)), 1fr));
}

.vm-an-skeleton {
    min-height: calc(var(--vt-space-32) * 2.5);
    border-radius: var(--vt-radius-panel);
    background: var(--vt-glass-thin-fill);
}

.vm-an-muted {
    margin: 0;
    color: var(--vt-text-muted);
}

.vm-an-split {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 1.3)), 1fr));
    gap: var(--vt-space-6);
    align-items: center;
    min-width: 0;
}

.vm-an-donut {
    min-width: 0;
}

.vm-an-bars,
.vm-an-legend {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
    min-width: 0;
}

.vm-an-bars li {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: var(--vt-space-1) var(--vt-space-3);
    font-size: var(--vt-fs-body-sm);
}

.vm-an-bars__value {
    color: var(--vt-text-muted);
    font-family: var(--vt-font-mono);
    font-size: var(--vt-fs-caption);
}

.vm-an-bars__track {
    grid-column: 1 / -1;
    height: var(--vt-space-1);
    border-radius: var(--vt-radius-full);
    background: var(--vt-glass-thin-fill);
    overflow: hidden;
}

.vm-an-bars__track i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: var(--vt-signature-gradient);
}

.vm-an-legend li {
    display: flex;
    justify-content: space-between;
    gap: var(--vt-space-3);
    font-size: var(--vt-fs-body-sm);
    min-width: 0;
}
</style>
