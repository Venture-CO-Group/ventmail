<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtBadge, VtBanner, VtButton, VtCard, VtEmptyState, VtIcon, VtKeyValue, VtKpiCard, VtPageHeader, VtProgressBar, type VtColumn } from '@venture-co-group/ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../Components/Form/VmCard.vue';
import VmTable from '../Components/Form/VmTable.vue';
import VmStateBadge from '../Components/Platform/VmStateBadge.vue';
import VmIcon from '../Components/Shell/VmIcon.vue';
import { useFormat } from '../composables/useFormat';
import AppLayout from '../Layouts/AppLayout.vue';

/**
 * A NYITÓKÉPERNYŐ (v1.1 · D284, a mockup „Overview" képernyője): KPI-sor sparkline-nal,
 * legutóbbi kampányok, kézbesíthetőség és teendők — és a küldést MEGÁLLÍTÓ számok (kvóta,
 * hitelesítetlen domain, tiltólista). Minden szám a profil formátumában (`useFormat`).
 */
interface RecentMessage {
    uuid: string;
    subject: string;
    status: string;
    recipients: string[];
    created_at: string | null;
}

interface Kpi {
    value: number | null;
    previous?: number | null;
    added?: number;
    series: Array<number | null>;
}

interface Kpis {
    available: boolean;
    days?: number;
    sent?: Kpi;
    deliveryRate?: Kpi;
    openRate?: Kpi;
    activeContacts?: Kpi;
}

interface Deliverability {
    available: boolean;
    bounceRate: number | null;
    complaintRate: number | null;
    bounceThreshold: number;
    complaintThreshold: number;
    records: Record<'spf' | 'dkim' | 'dmarc' | 'tracking', string>;
    healthy: boolean;
}

interface Todo {
    key: 'drafts' | 'unverifiedDomains' | 'pendingContacts' | 'deadLetters';
    count: number;
    href: string;
    tone: 'warning' | 'success';
}

const props = defineProps<{
    quota: { remaining: number };
    domains: { total: number; verified: number; failed: number };
    suppressions: number;
    delivery: { delivered: number; opened: number; clicked: number; bounced: number; bounce_rate: number } | null;
    recent: RecentMessage[];
    workspaceName: string;
    kpis: Kpis | null;
    deliverability: Deliverability | null;
    todos: Todo[];
    sendingMode: { live: boolean; allowlistEnforced: boolean; marketingEnabled: boolean };
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage();

const abilities = computed(() => (page.props.abilities as string[] | undefined) ?? []);
const can = (ability: string): boolean => abilities.value.includes(ability);

// A HITELESÍTETLEN DOMAIN nem statisztika, hanem AKADÁLY: a PreSendGate 1. kapuja tiltja
// a küldést róla. Ezért figyelmeztetés, nem egy szám a többi között.
const hasUnverified = computed(() => props.domains.total > props.domains.verified);

const restrictedMode = computed(() => props.sendingMode.allowlistEnforced || !props.sendingMode.marketingEnabled);

/* ---------- KPI-sor ---------- */

type Trend = { text: string; direction: 'up' | 'down' | 'flat' };

/** Mennyiség: százalékos változás az előző időszakhoz. */
function relativeTrend(kpi: Kpi): Trend | null {
    if (kpi.value === null || kpi.previous === null || kpi.previous === undefined) return null;
    if (kpi.previous === 0) return kpi.value === 0 ? null : { text: t('dashboard.kpi.newActivity'), direction: 'up' };

    const delta = ((kpi.value - kpi.previous) / kpi.previous) * 100;

    return { text: t('dashboard.kpi.vsPrevious', { delta: format.percent(Math.abs(delta)) }), direction: delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat' };
}

/** Arány: különbség százalékpontban. */
function pointTrend(kpi: Kpi): Trend | null {
    if (kpi.value === null || kpi.previous === null || kpi.previous === undefined) return null;

    const delta = kpi.value - kpi.previous;

    return { text: t('dashboard.kpi.points', { delta: format.number(Math.abs(delta), { maximumFractionDigits: 1 }) }), direction: delta > 0 ? 'up' : delta < 0 ? 'down' : 'flat' };
}

const kpiCards = computed(() => {
    const k = props.kpis;

    if (!k?.available || !k.sent || !k.deliveryRate || !k.openRate || !k.activeContacts) return [];

    return [
        { key: 'sent', label: t('dashboard.kpi.sent'), value: format.number(k.sent.value), trend: relativeTrend(k.sent), series: k.sent.series },
        { key: 'deliveryRate', label: t('dashboard.kpi.deliveryRate'), value: format.percent(k.deliveryRate.value), trend: pointTrend(k.deliveryRate), series: k.deliveryRate.series },
        { key: 'openRate', label: t('dashboard.kpi.openRate'), value: format.percent(k.openRate.value), trend: pointTrend(k.openRate), series: k.openRate.series },
        {
            key: 'activeContacts',
            label: t('dashboard.kpi.activeContacts'),
            value: format.number(k.activeContacts.value),
            trend: { text: t('dashboard.kpi.added', { count: format.number(k.activeContacts.added ?? 0) }), direction: (k.activeContacts.added ?? 0) > 0 ? 'up' : 'flat' } as Trend,
            series: k.activeContacts.series,
        },
    ];
});

/*
 | A RÁCS OSZLOPSZÁMA (UI-polírozás · 4, review): a 4 / 2 / 1 sávot a CSS dönti el (töréspont nélkül,
 | lásd a stílust); itt csak KIOLVASSUK a számított sávok számát. Ebből jön a széles kártyák
 | elhelyezése (4 sávnál a kampányok 3 + a kézbesíthetőség 1, különben mindkettő teljes sor) és a
 | táblák oszlopkészlete 1 sávnál (mobilon a táblák nem csordulhatnak túl).
 */
const gridRef = ref<HTMLElement | null>(null);
const gridColumns = ref(0);
let gridObserver: ResizeObserver | null = null;

function readColumns(): void {
    if (gridRef.value) gridColumns.value = getComputedStyle(gridRef.value).gridTemplateColumns.split(' ').filter(Boolean).length;
}

onMounted(() => {
    readColumns();

    if (typeof ResizeObserver !== 'undefined' && gridRef.value) {
        gridObserver = new ResizeObserver(readColumns);
        gridObserver.observe(gridRef.value);
    }
});

onBeforeUnmount(() => gridObserver?.disconnect());

const wide = computed(() => gridColumns.value >= 4);
const single = computed(() => gridColumns.value === 1);

/* ---------- Kézbesíthetőség ---------- */


const recordRows = computed(() =>
    props.deliverability ? (['spf', 'dkim', 'dmarc', 'tracking'] as const).map((key) => ({ key, state: props.deliverability!.records[key] })) : [],
);

/* ---------- Napló ---------- */

type Row = { subject: string; status: string; recipient: string; created: string };

const columns = computed<VtColumn<Row>[]>(() => [
    ...(single.value ? [] : [{ key: 'created', label: t('dashboard.recentColumns.created') } as VtColumn<Row>]),
    { key: 'subject', label: t('dashboard.recentColumns.subject') },
    ...(single.value ? [] : [{ key: 'recipient', label: t('dashboard.recentColumns.recipient') } as VtColumn<Row>]),
    { key: 'status', label: t('dashboard.recentColumns.status') },
]);

const rows = computed<Row[]>(() =>
    props.recent.map((message) => ({
        subject: message.subject,
        status: message.status,
        recipient: message.recipients[0] ?? '—',
        created: format.dateTime(message.created_at),
    })),
);
</script>

<template>
    <AppLayout>
        <VtPageHeader
            :overline="t('dashboard.eyebrow', { workspace: workspaceName, days: kpis?.days ?? 30 })"
            :title="t('dashboard.title')"
            :description="t('page.overview.lead')"
            data-testid="dashboard-header"
        >
            <template #actions>
                <VtButton v-if="can('audience.manage')" variant="secondary" data-testid="dashboard-import" @click="router.visit('/audience/import')">
                    <VmIcon name="upload" /> {{ t('dashboard.import') }}
                </VtButton>
                <VtButton v-if="can('campaign.create')" data-testid="dashboard-new-campaign" @click="router.visit('/campaigns?new=1')">
                    <VmIcon name="plus" /> {{ t('dashboard.newCampaign') }}
                </VtButton>
            </template>
        </VtPageHeader>

        <VtBanner
            v-if="restrictedMode"
            tone="info"
            role="status"
            :title="t(sendingMode.allowlistEnforced ? 'dashboard.mode.allowlist' : 'dashboard.mode.marketingOff')"
            :description="t('dashboard.mode.hint')"
            data-testid="sending-mode"
        />

        <VtBanner v-if="hasUnverified" tone="warning" role="status" data-testid="unverified-warning">
            {{ t('dashboard.unverifiedWarning') }}
        </VtBanner>

        <!--
         | A KÁRTYARÁCS (UI-polírozás · 4. pont): EGYETLEN determinisztikus CSS grid, 4 / 2 / 1
         | oszlop, token-gap. Minden kártya egy cella (`data-dash-cell`); egy sor kártyái azonos
         | tetővel és aljjal állnak (a grid nyújtja őket). A széles kártyák 4 sávnál 3 + 1 sávot
         | kapnak (kampányok + kézbesíthetőség), 2 és 1 sávnál teljes sort — a sávszámot a CSS dönti
         | el, a komponens csak kiolvassa (`readColumns`), így implicit oszlop sosem keletkezik (D311).
         | A számok a közös `VtKpiCard`-ok (Figma KpiCard), a teendők egy `VtCard`.
         -->
        <div ref="gridRef" class="vm-dash-grid" :class="{ 'vm-dash-grid--tight': gridColumns > 0 && !wide }" data-testid="dashboard-grid" :data-columns="gridColumns">
            <div class="vm-dash-metrics" data-testid="dashboard-metrics">
                <VtKpiCard
                    v-for="card in kpiCards"
                    :key="card.key"
                    :label="card.label"
                    :value="card.value"
                    :delta="card.trend ? `${card.trend.direction === 'up' ? '▲' : card.trend.direction === 'down' ? '▼' : '•'} ${card.trend.text}` : t('dashboard.kpi.noPrevious')"
                    :trend="card.trend?.direction ?? 'flat'"
                    :spark="card.series.filter((value): value is number => value !== null)"
                    :data-testid="`kpi-${card.key}`"
                    :data-dash-cell="`kpi-${card.key}`"
                />

                <!-- A KPI-blokk nélkül (szerep / keret-túllépés) a küldést megállító számok maradnak. -->
                <VtCard v-if="kpis && !kpis.available" class="vm-dash-full" data-testid="kpis-unavailable" data-dash-cell="kpis-unavailable">
                    <p class="vm-dash-muted">{{ t('dashboard.kpi.unavailable') }}</p>
                </VtCard>

                <VtKpiCard :label="t('dashboard.quota')" :value="format.number(quota.remaining)" data-testid="metric-quota" data-dash-cell="quota" />

                <VtKpiCard
                    :label="t('dashboard.domains')"
                    :value="`${format.number(domains.verified)} / ${format.number(domains.total)}`"
                    :delta="domains.total > 0 ? (hasUnverified ? t('dashboard.domainsUnverified', { count: format.number(domains.total - domains.verified) }) : t('dashboard.domainsAllVerified')) : undefined"
                    :trend="hasUnverified ? 'down' : 'up'"
                    data-testid="metric-domains"
                    data-dash-cell="domains"
                />

                <VtKpiCard
                    :label="t('dashboard.suppressions')"
                    :value="format.number(suppressions)"
                    :delta="t('dashboard.suppressionsHint')"
                    trend="flat"
                    data-testid="metric-suppressions"
                    data-dash-cell="suppressions"
                />

                <!-- TEENDŐK — a metrika-sor negyedik cellája (a sor így tele van). -->
                <VtCard :title="t('dashboard.todos.title')" data-testid="dashboard-todos" data-dash-cell="todos">
                    <ul class="vm-dash-todos">
                        <li v-for="todo in todos" :key="todo.key" :data-testid="`todo-${todo.key}`" :data-count="todo.count">
                            <VtIcon :name="todo.tone === 'warning' ? 'alert' : 'check'" :size="16" class="vm-dash-todo__icon" :class="`vm-dash-todo__icon--${todo.tone}`" />
                            <Link v-if="todo.count > 0" :href="todo.href" class="vm-dash-todo__text" :data-testid="`todo-${todo.key}-action`">
                                {{ t(`dashboard.todos.${todo.key}`, { count: format.number(todo.count) }) }}
                            </Link>
                            <span v-else class="vm-dash-todo__text">{{ t(`dashboard.todos.${todo.key}None`) }}</span>
                        </li>
                    </ul>
                </VtCard>
            </div>

            <!--
             | A széles kártyák (Figma Overview): 4 sávnál a legutóbbi üzenetek 3 + a kézbesíthetőség 1;
             | 2 és 1 sávnál mindkettő teljes sor.
             -->
            <!-- LEGUTÓBBI ÜZENETEK (napló) -->
            <VmCard
                :title="t('dashboard.recent')"
                :flush="delivery !== null && rows.length > 0"
                :class="wide && deliverability ? 'vm-dash-span-3' : 'vm-dash-full'"
                data-dash-cell="recent"
            >
                <template v-if="delivery !== null" #actions>
                    <Link href="/logs" class="vm-dash-link" data-testid="dashboard-logs-all">
                        <VtIcon name="arrow-up-right" :size="16" /> {{ t('nav.logs') }}
                    </Link>
                </template>

                <!-- A JOGOSULTSÁG HIÁNYA SZÖVEG, nem üres doboz: az `author` szerep szándékosan
                     nem kap `logs.view`-t, és ezt meg kell mondani, nem elhallgatni. -->
                <p v-if="delivery === null" data-testid="logs-forbidden">{{ t('dashboard.noAccess') }}</p>
                <VtEmptyState v-else-if="!rows.length" :title="t('dashboard.empty')" icon="inbox" data-testid="recent-empty" />
                <VmTable v-else :columns="columns" :rows="rows" :caption="t('dashboard.recent')" data-testid="recent-table">
                    <template #cell="{ column, value }">
                        <VmStateBadge v-if="column.key === 'status'" :state="String(value)" />
                        <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                    </template>
                </VmTable>
            </VmCard>

            <!-- KÉZBESÍTHETŐSÉG -->
            <VmCard
                v-if="deliverability"
                :title="t('dashboard.deliverability.title')"
                :class="wide ? 'vm-dash-span-1' : 'vm-dash-full'"
                data-testid="dashboard-deliverability"
                data-dash-cell="deliverability"
            >
                <template #badge>
                    <VtBadge :tone="deliverability.healthy ? 'success' : 'warning'" data-testid="deliverability-health">
                        {{ t(deliverability.healthy ? 'dashboard.deliverability.healthy' : 'dashboard.deliverability.attention') }}
                    </VtBadge>
                </template>

                <div class="vm-dash-kvs">
                    <VtKeyValue :label="t('dashboard.deliverability.bounce')">
                        <span data-testid="deliverability-bounce">{{ format.percent(deliverability.bounceRate, 2) }}</span>
                    </VtKeyValue>
                    <VtProgressBar
                        :value="Math.min(deliverability.bounceRate ?? 0, deliverability.bounceThreshold)"
                        :max="deliverability.bounceThreshold"
                        :tone="(deliverability.bounceRate ?? 0) >= deliverability.bounceThreshold ? 'danger' : 'accent'"
                        :label="t('dashboard.deliverability.bounce')"
                    />
                    <VtKeyValue :label="t('dashboard.deliverability.complaint')">
                        <span data-testid="deliverability-complaint">{{ format.percent(deliverability.complaintRate, 3) }}</span>
                    </VtKeyValue>
                    <VtProgressBar
                        :value="Math.min(deliverability.complaintRate ?? 0, deliverability.complaintThreshold)"
                        :max="deliverability.complaintThreshold"
                        :tone="(deliverability.complaintRate ?? 0) >= deliverability.complaintThreshold ? 'danger' : 'accent'"
                        :label="t('dashboard.deliverability.complaint')"
                    />
                    <VtKeyValue v-for="record in recordRows" :key="record.key" :label="t(`dashboard.deliverability.${record.key}`)">
                        <VmStateBadge :state="record.state" :testid="`deliverability-${record.key}`" />
                    </VtKeyValue>
                </div>

                <template #footer>
                    <VtButton variant="secondary" size="sm" data-testid="dashboard-domains" @click="router.visit('/domains')">
                        {{ t('dashboard.deliverability.openDomains') }}
                    </VtButton>
                </template>
            </VmCard>

        </div>
    </AppLayout>
</template>

<style scoped>
/*
 | A KÁRTYARÁCS — 4 / 2 / 1 oszlop, töréspont-literál és media query NÉLKÜL (lásd D18: a media
 | query nem olvas `var()`-t, breakpoint-token nincs). A sáv minimális szélessége a TARTÓ
 | szélességéből (`100%`) számolt, három állású kapcsoló:
 |
 |   tartó ≥ T4  → negyed (− ε) → pontosan 4 sáv
 |   T2 ≤ … < T4 → fél (− ε)    → pontosan 2 sáv
 |   tartó < T2  → 100%         → 1 sáv
 |
 | A kapcsoló a `(T − 100%) * 999` előjelén fordul (negatív → a `max` a másik ágat választja).
 | Az ε (`gap / 4`) a kerekítés ellen van: a pontos negyed lebegőpontos hibával 3 sávra eshetne.
 | A sávok `1fr`-rel töltik ki a sort, tehát a kártyák széle minden sorban egy vonalba esik.
 */
.vm-dash-grid {
    --vm-dash-gap: var(--vt-space-6);
    /* T4 = 928 px: 1280-nál a 248 px-es sidebar mellett 952 px a tartó (Unified UI v2) — még 4 sáv. */
    --vm-dash-t4: calc(calc(var(--vt-space-8) * 4) * 7.25);
    --vm-dash-t2: calc(calc(var(--vt-space-8) * 4) * 4);
    --vm-dash-q: calc((100% - 3 * var(--vm-dash-gap)) / 4 - var(--vm-dash-gap) / 4);
    --vm-dash-h: calc((100% - var(--vm-dash-gap)) / 2 - var(--vm-dash-gap) / 4);

    display: grid;
    grid-template-columns: repeat(
        auto-fill,
        minmax(max(max(var(--vm-dash-q), min(var(--vm-dash-h), calc((var(--vm-dash-t4) - 100%) * 999))), min(100%, calc((var(--vm-dash-t2) - 100%) * 999))), 1fr)
    );
    gap: var(--vm-dash-gap);
    align-items: stretch;
}

/* A metrika-sor a külső rács SÁVJAIT használja (subgrid): a kártyák a rács oszlopaiba esnek. */
.vm-dash-metrics {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: subgrid;
    gap: var(--vm-dash-gap);
    align-items: stretch;
}

/*
 | A rács táblái RÖGZÍTETT elrendezésűek (a 3 sávos kártyában és keskeny rácsnál is): az oszlopok a
 | kártya szélességén osztoznak, a hosszú érték levágva (`vm-truncate`) vagy tördelve — vízszintes
 | túlcsordulás nincs.
 */
.vm-dash-grid :deep(.vt-dt__el) {
    table-layout: fixed;
}

.vm-dash-grid :deep(.vm-truncate) {
    max-inline-size: 100%;
}

.vm-dash-span-1 {
    grid-column: span 1;
}

.vm-dash-full {
    grid-column: 1 / -1;
}

.vm-dash-muted {
    color: var(--vt-text-secondary);
}

.vm-dash-link {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-1);
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-s-medium-size);
    text-decoration: none;
}

.vm-dash-link:hover {
    color: var(--vt-text-primary);
}

.vm-dash-span-3 {
    grid-column: span 3;
}

.vm-dash-kvs {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
}

.vm-dash-todos {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-dash-todos li {
    display: flex;
    align-items: flex-start;
    gap: var(--vt-space-2);
    font-size: var(--vt-type-body-s-size);
    line-height: var(--vt-type-body-s-line-height);
}

.vm-dash-todo__icon {
    margin-block-start: calc(var(--vt-space-1) / 4);
}

.vm-dash-todo__text {
    flex: 1 1 auto;
    min-width: 0;
    color: var(--vt-text-secondary);
    overflow-wrap: anywhere;
}

a.vm-dash-todo__text {
    color: var(--vt-text-primary);
    text-decoration: none;
}

a.vm-dash-todo__text:hover {
    color: var(--vt-accent-hover);
}

.vm-dash-todo__icon--warning {
    color: var(--vt-status-warning);
}

.vm-dash-todo__icon--success {
    color: var(--vt-status-success);
}
</style>
