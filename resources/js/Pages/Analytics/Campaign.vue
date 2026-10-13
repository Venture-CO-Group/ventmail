<script setup lang="ts">
import { Deferred, Link } from '@inertiajs/vue3';
import { VtBadge, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CampaignSection } from '../../Components/Analytics/types';
import VmHeatmap from '../../Components/Analytics/VmHeatmap.vue';
import VmChartCard from '../../Components/Charts/VmChartCard.vue';
import VmLineChart from '../../Components/Charts/VmLineChart.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * KAMPÁNY-RIPORT.
 *
 * A NYITÁSI ARÁNY torzít (az Apple MPP előre letölti a képeket), ezért a KATTINTÁS az,
 * amit kiemelünk — az szándékos cselekvés. A fenntartás a képernyőn van, nem a
 * dokumentációban: aki a számot nézi, annak ott kell látnia.
 *
 * v1.1 · 8. modul (D241): a variáns kulcsa `label` (a korábbi `variant`-kulcs sosem létezett,
 * ezért a név és a győztes nem látszott); a napi idősor (`daily`) grafikonon; a hőtérkép és a
 * nyitás/kattintás-idősor halasztott propként (`charts`).
 */
const props = defineProps<{
    campaign: { uuid: string; name: string; status: string };
    summary: Record<string, number | string>;
    variants: Array<Record<string, number | string | boolean | null>>;
    daily: Array<{ day: string; delivered: number; opened: number; clicked: number }>;
    winner: string | null;
    links: Array<{ url: string; clicks: number; unique_clicks: number; last_clicked_at: string | null }>;
    charts?: CampaignSection;
}>();

const { t } = useI18n();
const format = useFormat();

type LinkRow = { url: string; clicks: string; unique: string; last: string };

const linkColumns: VtColumn<LinkRow>[] = [
    { key: 'url', label: t('analytics.link'), mono: true },
    { key: 'clicks', label: t('analytics.clicks') },
    { key: 'unique', label: t('analytics.uniqueClicks') },
    { key: 'last', label: t('analytics.lastClick') },
];

const linkRows = computed<LinkRow[]>(() =>
    props.links.map((link) => ({
        url: link.url,
        clicks: String(link.clicks),
        unique: String(link.unique_clicks),
        last: link.last_clicked_at ? format.dateTime(link.last_clicked_at) : '—',
    })),
);

const metrics = computed(() =>
    ['recipients', 'delivered', 'opened', 'clicked', 'bounced', 'unsubscribed'].filter((key) => key in props.summary),
);

const pct = (value: unknown) => `${format.number(Number(value ?? 0), { maximumFractionDigits: 2 })}%`;

const dailySeries = computed(() => [
    { name: t('analytics.metric.delivered'), data: props.daily.map((d) => d.delivered), token: '--vt-color-success' },
    { name: t('analytics.metric.opened'), data: props.daily.map((d) => d.opened), token: '--vt-color-accent-500' },
    { name: t('analytics.metric.clicked'), data: props.daily.map((d) => d.clicked), token: '--vt-color-cyan-400' },
]);

const timeline = computed(() => props.charts?.timeline ?? { bucket: 'day' as const, points: [] });
const timelineSeries = computed(() => [
    { name: t('analytics.series.opens'), data: timeline.value.points.map((p) => p.opens), token: '--vt-color-accent-500', area: true },
    { name: t('analytics.series.clicks'), data: timeline.value.points.map((p) => p.clicks), token: '--vt-color-cyan-400', area: true },
]);

const dailyChart = ref<InstanceType<typeof VmLineChart> | null>(null);
const timelineChart = ref<InstanceType<typeof VmLineChart> | null>(null);
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('analytics.eyebrow')" :title="campaign.name">
            <Link :href="`/analytics?campaign=${campaign.uuid}`" class="vm-linkbutton">{{ t('analytics.title') }}</Link>
        </VmPageHeader>

        <div class="vm-metrics" data-testid="report-metrics">
            <VmCard v-for="key in metrics" :key="key">
                <p class="vm-metric__label">{{ t(`analytics.metric.${key}`) }}</p>
                <p class="vm-metric__value" :data-testid="`metric-${key}`">{{ summary[key] }}</p>
            </VmCard>
        </div>

        <VmCard>
            <p data-testid="analytics-mpp">{{ t('analytics.mppNote') }}</p>
        </VmCard>

        <VmCard v-if="variants.length" data-testid="report-variants">
            <h2>{{ t('analytics.variants') }}</h2>

            <div v-for="variant in variants" :key="String(variant.label)" class="vm-cluster" :data-testid="`variant-${variant.label}`">
                <strong>{{ variant.label }}</strong>
                <span v-if="variant.subject" class="vm-truncate">{{ variant.subject }}</span>
                <span>{{ t('analytics.metric.delivered') }}: {{ variant.delivered }}</span>
                <span>{{ t('analytics.kpi.openRate') }}: {{ pct(variant.open_rate) }}</span>
                <span>{{ t('analytics.kpi.clickRate') }}: {{ pct(variant.click_rate) }}</span>
                <VtBadge v-if="winner !== null && winner === variant.label" tone="success" size="sm" data-testid="variant-winner">{{ t('analytics.winner') }}</VtBadge>
            </div>
        </VmCard>

        <VmChartCard id="daily" :title="t('analytics.chart.daily')" :subtitle="t('analytics.eventsNote')" :context="campaign.name" :chart="daily.length ? dailyChart : null">
            <VmLineChart v-if="daily.length" ref="dailyChart" :categories="daily.map((d) => d.day)" :series="dailySeries" :label="t('analytics.chart.daily')" testid="chart-daily" />
            <p v-else data-testid="daily-empty">{{ t('analytics.noData') }}</p>
        </VmChartCard>

        <Deferred data="charts">
            <template #fallback><p>…</p></template>
            <VmChartCard id="open-click" :title="t('analytics.chart.openClick')" :subtitle="t(`analytics.bucket.${timeline.bucket}`)" :context="campaign.name" :chart="timeline.points.length ? timelineChart : null">
                <VmLineChart v-if="timeline.points.length" ref="timelineChart" :categories="timeline.points.map((p) => p.at.replace('T', ' '))" :series="timelineSeries" :label="t('analytics.chart.openClick')" testid="chart-open-click" />
                <p v-else>{{ t('analytics.noData') }}</p>
            </VmChartCard>

            <VmChartCard id="heatmap" :title="t('analytics.chart.heatmap')" :csv-href="`/analytics/campaigns/${campaign.uuid}/links.csv`">
                <VmHeatmap :campaign-uuid="campaign.uuid" :links="charts?.links ?? []" />
            </VmChartCard>
        </Deferred>

        <VmCard>
            <h2>{{ t('analytics.clickMap') }}</h2>
            <!-- A KLIKK-TÉRKÉP a retenció után is megmarad AGGREGÁLVA: a címzett-dimenzió
                 tűnik el belőle, a link-szintű szám nem. -->
            <p v-if="!linkRows.length" data-testid="links-empty">{{ t('analytics.noClicks') }}</p>
            <VmTable v-else :columns="linkColumns" :rows="linkRows" :caption="t('analytics.clickMap')" data-testid="links-table" />
        </VmCard>
    </AppLayout>
</template>
