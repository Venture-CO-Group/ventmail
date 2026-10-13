<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtSegmented, VtSelect, type VtColumn } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmLineChart from '../../Components/Charts/VmLineChart.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmStat from '../../Components/Form/VmStat.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * KÜLDÉSI STATISZTIKA (v1.1 · 7. modul, D175): küldött, kézbesített, bounce, panasz,
 * megnyitás, kattintás — domainenként és időben, a `domain_daily_stats` számlálóiból.
 *
 * A számok a retenció után is megmaradnak (aggregátum). A nyitás/kattintás esemény-szám.
 */
interface Metrics {
    sent: number;
    delivered: number;
    bounced: number;
    complained: number;
    opened: number;
    clicked: number;
    bounceRate?: number | null;
    complaintRate?: number | null;
    openRate?: number | null;
    clickRate?: number | null;
}

interface Report {
    totals: Array<Metrics & { domainId: number; domain: string | null }>;
    series: Array<Metrics & { day: string }>;
    sum: Metrics;
}

const props = defineProps<{
    days: number;
    domain: string | null;
    domains: Array<{ uuid: string; name: string }>;
    report: Report | null;
}>();

const { t, locale } = useI18n();

const rangeOptions = computed(() => [7, 30, 90, 365].map((days) => ({ value: String(days), label: t('plat.logs.lastDays', { days }) })));
const domainOptions = computed(() => [{ value: '', label: t('plat.logs.anyDomain') }, ...props.domains.map((item) => ({ value: item.uuid, label: item.name }))]);

function go(days: number, domain: string | null): void {
    const query: Record<string, string> = { days: String(days) };
    if (domain) query.domain = domain;

    router.get('/statistics', query, { preserveState: true, preserveScroll: true });
}

const fmt = (value: number): string => value.toLocaleString(locale.value);
const pct = (value: number | null | undefined): string => (value === null || value === undefined ? '—' : `${value.toLocaleString(locale.value)}%`);

const rate = (part: number, whole: number, digits = 1): number | null => (whole > 0 ? Math.round((part / whole) * 100 * 10 ** digits) / 10 ** digits : null);

const sum = computed(() => props.report?.sum ?? { sent: 0, delivered: 0, bounced: 0, complained: 0, opened: 0, clicked: 0 });

const chartSeries = computed(() => {
    const series = props.report?.series ?? [];

    return [
        { name: t('plat.stats.metric.sent'), data: series.map((row) => row.sent), token: '--vt-color-accent-400' },
        { name: t('plat.stats.metric.delivered'), data: series.map((row) => row.delivered), token: '--vt-color-success' },
        { name: t('plat.stats.metric.bounced'), data: series.map((row) => row.bounced), token: '--vt-color-danger' },
        { name: t('plat.stats.metric.complained'), data: series.map((row) => row.complained), token: '--vt-color-warning' },
        { name: t('plat.stats.metric.opened'), data: series.map((row) => row.opened), token: '--vt-color-cyan-400' },
        { name: t('plat.stats.metric.clicked'), data: series.map((row) => row.clicked), token: '--vt-color-violet-400' },
    ];
});

type Row = { domain: string; sent: string; delivered: string; bounced: string; complained: string; opened: string; clicked: string };

const columns = computed<VtColumn<Row>[]>(() => [
    { key: 'domain', label: t('logs.domain') },
    { key: 'sent', label: t('plat.stats.metric.sent'), align: 'right', mono: true },
    { key: 'delivered', label: t('plat.stats.metric.delivered'), align: 'right', mono: true },
    { key: 'bounced', label: t('plat.stats.metric.bounced'), align: 'right', mono: true },
    { key: 'complained', label: t('plat.stats.metric.complained'), align: 'right', mono: true },
    { key: 'opened', label: t('plat.stats.metric.opened'), align: 'right', mono: true },
    { key: 'clicked', label: t('plat.stats.metric.clicked'), align: 'right', mono: true },
]);

const rows = computed<Row[]>(() =>
    (props.report?.totals ?? []).map((row) => ({
        domain: row.domain ?? t('plat.stats.noDomain'),
        sent: fmt(row.sent),
        delivered: `${fmt(row.delivered)}`,
        bounced: `${fmt(row.bounced)} · ${pct(row.bounceRate)}`,
        complained: `${fmt(row.complained)} · ${pct(row.complaintRate)}`,
        opened: `${fmt(row.opened)} · ${pct(row.openRate)}`,
        clicked: `${fmt(row.clicked)} · ${pct(row.clickRate)}`,
    })),
);
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('plat.eyebrow.stats')" :title="t('plat.stats.title')" :lead="t('plat.stats.lead')">
            <div class="vm-filters">
                <div class="vm-filters__domain">
                <VtSelect
                    :model-value="domain ?? ''"
                    :options="domainOptions"
                    data-testid="stats-domain"
                    :aria-label="t('logs.domain')"
                    @update:model-value="go(days, String($event) || null)"
                />
                </div>
                <VtSegmented
                    :model-value="String(days)"
                    :options="rangeOptions"
                    :label="t('plat.stats.range')"
                    data-testid="stats-range"
                    @update:model-value="go(Number($event), domain)"
                />
            </div>
        </VmPageHeader>

        <p v-if="report === null" class="vm-banner vm-banner--warning" role="status" data-testid="stats-too-expensive">
            {{ t('plat.stats.tooExpensive') }}
        </p>

        <div class="vm-tiles" data-testid="stats-tiles">
            <VmStat :label="t('plat.stats.metric.sent')" :value="fmt(sum.sent)" />
            <VmStat :label="t('plat.stats.metric.delivered')" :value="fmt(sum.delivered)" :hint="pct(rate(sum.delivered, sum.sent))" />
            <VmStat :label="t('plat.stats.metric.bounced')" :value="fmt(sum.bounced)" :hint="pct(rate(sum.bounced, sum.sent, 2))" :tone="(rate(sum.bounced, sum.sent) ?? 0) > 2 ? 'danger' : 'neutral'" />
            <VmStat :label="t('plat.stats.metric.complained')" :value="fmt(sum.complained)" :hint="pct(rate(sum.complained, sum.sent, 3))" :tone="(rate(sum.complained, sum.sent, 3) ?? 0) >= 0.3 ? 'danger' : 'neutral'" />
            <VmStat :label="t('plat.stats.metric.opened')" :value="fmt(sum.opened)" :hint="pct(rate(sum.opened, sum.delivered))" />
            <VmStat :label="t('plat.stats.metric.clicked')" :value="fmt(sum.clicked)" :hint="pct(rate(sum.clicked, sum.delivered))" />
        </div>

        <VmCard>
            <h2>{{ t('plat.stats.overTime') }}</h2>
            <p v-if="!(report?.series.length)" data-testid="stats-empty">{{ t('plat.stats.empty') }}</p>
            <VmLineChart
                v-else
                :categories="report!.series.map((row) => row.day)"
                :series="chartSeries"
                :label="t('plat.stats.overTime')"
                testid="stats-chart"
            />
        </VmCard>

        <VmCard>
            <h2>{{ t('plat.stats.byDomain') }}</h2>
            <VmTable :columns="columns" :rows="rows" :caption="t('plat.stats.byDomain')" data-testid="stats-domains" />
            <p class="vm-muted">{{ t('plat.stats.note') }}</p>
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vm-filters__domain {
    width: min(100%, calc(var(--vt-space-32) * 2));
}

.vm-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-32) * 1.25)), 1fr));
    gap: var(--vt-space-4);
}

.vm-muted {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}
</style>
