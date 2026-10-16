<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtBadge, VtBanner, VtButton, type VtColumn } from '@venture-co-group/ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmSparkline from '../Components/Charts/VmSparkline.vue';
import VmCard from '../Components/Form/VmCard.vue';
import VmTable from '../Components/Form/VmTable.vue';
import VmStateBadge from '../Components/Platform/VmStateBadge.vue';
import VmIcon from '../Components/Shell/VmIcon.vue';
import VmPageHeader from '../Components/Studio/VmPageHeader.vue';
import VmStatusBadge from '../Components/Studio/VmStatusBadge.vue';
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

interface CampaignRow {
    uuid: string;
    name: string;
    displayStatus: string;
    recipients: number;
    openRate: number | null;
    clickRate: number | null;
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
    campaigns: CampaignRow[] | null;
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

/* ---------- Legutóbbi kampányok ---------- */

type CampaignTableRow = { name: string; status: string; recipients: string; open: string; click: string; uuid: string };

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

const campaignColumns = computed<VtColumn<CampaignTableRow>[]>(() => [
    { key: 'name', label: t('dashboard.campaigns.name') },
    { key: 'status', label: t('dashboard.campaigns.status') },
    ...(single.value
        ? []
        : ([
              { key: 'recipients', label: t('dashboard.campaigns.recipients'), align: 'right' },
              { key: 'open', label: t('dashboard.campaigns.open'), align: 'right' },
              { key: 'click', label: t('dashboard.campaigns.click'), align: 'right' },
          ] as VtColumn<CampaignTableRow>[])),
]);

const campaignRows = computed<CampaignTableRow[]>(() =>
    (props.campaigns ?? []).map((campaign) => ({
        uuid: campaign.uuid,
        name: campaign.name,
        status: campaign.displayStatus,
        recipients: campaign.recipients ? format.number(campaign.recipients) : '—',
        open: format.percent(campaign.openRate),
        click: format.percent(campaign.clickRate),
    })),
);

/* ---------- Kézbesíthetőség ---------- */

// A sáv kitöltése a küszöbhöz mérten (a küszöb = teli sáv): a 0,48%-os bounce így látható.
const fill = (value: number | null, threshold: number): string => `${Math.min(100, Math.max(0, ((value ?? 0) / threshold) * 100))}%`;

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
        <VmPageHeader
            :eyebrow="t('dashboard.eyebrow', { workspace: workspaceName, days: kpis?.days ?? 30 })"
            :title="t('dashboard.title')"
            :lead="t('dashboard.lead')"
            testid="dashboard-header"
        >
            <VtButton v-if="can('audience.manage')" variant="secondary" data-testid="dashboard-import" @click="router.visit('/audience/import')">
                <VmIcon name="upload" /> {{ t('dashboard.import') }}
            </VtButton>
            <VtButton v-if="can('campaign.create')" data-testid="dashboard-new-campaign" @click="router.visit('/campaigns?new=1')">
                <VmIcon name="plus" /> {{ t('dashboard.newCampaign') }}
            </VtButton>
        </VmPageHeader>

        <VtBanner v-if="restrictedMode" tone="info" class="vm-dash-mode" role="status" data-testid="sending-mode">
            <VmIcon name="info" />
            <span>
                <strong>{{ t(sendingMode.allowlistEnforced ? 'dashboard.mode.allowlist' : 'dashboard.mode.marketingOff') }}</strong>
                {{ t('dashboard.mode.hint') }}
            </span>
        </VtBanner>

        <VtBanner v-if="hasUnverified" tone="warning" role="status" data-testid="unverified-warning">
            {{ t('dashboard.unverifiedWarning') }}
        </VtBanner>

        <!--
         | A KÁRTYARÁCS (UI-polírozás · 4. pont): EGYETLEN determinisztikus CSS grid, 4 / 2 / 1
         | oszlop, token-gap. Minden kártya egy cella (`data-dash-cell`); egy sor kártyái azonos
         | tetővel és aljjal állnak (a grid nyújtja őket). A széles kártyák 4 sávnál 3 + 1 sávot
         | kapnak (kampányok + kézbesíthetőség), 2 és 1 sávnál teljes sort — a sávszámot a CSS dönti
         | el, a komponens csak kiolvassa (`readColumns`), így implicit oszlop sosem keletkezik (D311).
         -->
        <div ref="gridRef" class="vm-dash-grid" :class="{ 'vm-dash-grid--tight': gridColumns > 0 && !wide }" data-testid="dashboard-grid" :data-columns="gridColumns">
            <div class="vm-dash-metrics" data-testid="dashboard-metrics">
                <VmCard v-for="card in kpiCards" :key="card.key" glass :data-testid="`kpi-${card.key}`" :data-dash-cell="`kpi-${card.key}`">
                    <div class="vm-dash-kpi">
                        <span class="vm-dash-kpi__label">{{ card.label }}</span>
                        <strong class="vm-dash-kpi__value" :data-testid="`kpi-${card.key}-value`">{{ card.value }}</strong>
                        <span v-if="card.trend" class="vm-dash-kpi__trend" :class="`vm-dash-kpi__trend--${card.trend.direction}`">
                            <span aria-hidden="true">{{ card.trend.direction === 'up' ? '▲' : card.trend.direction === 'down' ? '▼' : '•' }}</span>
                            {{ card.trend.text }}
                        </span>
                        <span v-else class="vm-dash-kpi__trend">{{ t('dashboard.kpi.noPrevious') }}</span>
                        <VmSparkline :values="card.series" :testid="`kpi-${card.key}-spark`" />
                    </div>
                </VmCard>

                <!-- A KPI-blokk nélkül (szerep / keret-túllépés) a küldést megállító számok maradnak. -->
                <VmCard v-if="kpis && !kpis.available" glass class="vm-dash-full" data-testid="kpis-unavailable" data-dash-cell="kpis-unavailable">
                    <p class="vm-dash-muted">{{ t('dashboard.kpi.unavailable') }}</p>
                </VmCard>

                <VmCard glass data-dash-cell="quota">
                    <div class="vm-dash-kpi">
                        <span class="vm-dash-kpi__label">{{ t('dashboard.quota') }}</span>
                        <strong class="vm-dash-kpi__value" data-testid="metric-quota">{{ format.number(quota.remaining) }}</strong>
                    </div>
                </VmCard>

                <VmCard glass data-dash-cell="domains">
                    <div class="vm-dash-kpi">
                        <span class="vm-dash-kpi__label">{{ t('dashboard.domains') }}</span>
                        <strong class="vm-dash-kpi__value" data-testid="metric-domains">{{ format.number(domains.verified) }} / {{ format.number(domains.total) }}</strong>
                    </div>
                </VmCard>

                <VmCard glass data-dash-cell="suppressions">
                    <div class="vm-dash-kpi">
                        <span class="vm-dash-kpi__label">{{ t('dashboard.suppressions') }}</span>
                        <strong class="vm-dash-kpi__value" data-testid="metric-suppressions">{{ format.number(suppressions) }}</strong>
                    </div>
                </VmCard>

                <!-- TEENDŐK — a metrika-sor negyedik cellája (a sor így tele van). -->
                <VmCard glass data-testid="dashboard-todos" data-dash-cell="todos">
                    <h3>{{ t('dashboard.todos.title') }}</h3>
                    <ul class="vm-dash-todos">
                        <li v-for="todo in todos" :key="todo.key" :data-testid="`todo-${todo.key}`" :data-count="todo.count">
                            <VmIcon :name="todo.tone === 'warning' ? 'warning' : 'check'" class="vm-dash-todo__icon" :class="`vm-dash-todo__icon--${todo.tone}`" />
                            <span class="vm-dash-todo__text">
                                {{ todo.count > 0 ? t(`dashboard.todos.${todo.key}`, { count: format.number(todo.count) }) : t(`dashboard.todos.${todo.key}None`) }}
                            </span>
                            <VtButton v-if="todo.count > 0" variant="ghost" size="sm" :data-testid="`todo-${todo.key}-action`" @click="router.visit(todo.href)">
                                {{ t(`dashboard.todos.action.${todo.key}`) }}
                            </VtButton>
                        </li>
                    </ul>
                </VmCard>
            </div>

            <!-- A széles kártyák: 4 sávnál kampányok 3 + kézbesíthetőség 1, különben mindkettő teljes sor. -->
                <!-- LEGUTÓBBI KAMPÁNYOK -->
                <VmCard
                    v-if="campaigns !== null"
                    glass
                    :class="wide && deliverability ? 'vm-dash-span-3' : 'vm-dash-full'"
                    data-testid="dashboard-campaigns"
                    data-dash-cell="campaigns"
                >
                    <div class="vm-dash-head">
                        <h2>{{ t('dashboard.campaigns.title') }}</h2>
                        <Link href="/campaigns" class="vm-dash-link" data-testid="dashboard-campaigns-all">
                            {{ t('dashboard.campaigns.all') }} <VmIcon name="chevron" class="vm-dash-link__chevron" />
                        </Link>
                    </div>

                    <p v-if="!campaignRows.length" class="vm-dash-muted" data-testid="dashboard-campaigns-empty">{{ t('dashboard.campaigns.empty') }}</p>
                    <VmTable v-else :columns="campaignColumns" :rows="campaignRows" :caption="t('dashboard.campaigns.title')">
                        <template #cell="{ row, column, value }">
                            <Link v-if="column.key === 'name'" :href="`/campaigns/${row.uuid}`" class="vm-dash-campaign" :title="String(value)">{{ value }}</Link>
                            <VmStatusBadge v-else-if="column.key === 'status'" :status="String(value)" />
                            <span v-else class="vm-dash-num">{{ value }}</span>
                        </template>
                    </VmTable>
                </VmCard>

                <!-- KÉZBESÍTHETŐSÉG -->
                <VmCard
                    v-if="deliverability"
                    glass
                    glow
                    :class="wide && campaigns !== null ? 'vm-dash-span-1' : 'vm-dash-full'"
                    data-testid="dashboard-deliverability"
                    data-dash-cell="deliverability"
                >
                    <div class="vm-dash-head">
                        <h2>{{ t('dashboard.deliverability.title') }}</h2>
                        <VtBadge :tone="deliverability.healthy ? 'success' : 'warning'" data-testid="deliverability-health">
                            {{ t(deliverability.healthy ? 'dashboard.deliverability.healthy' : 'dashboard.deliverability.attention') }}
                        </VtBadge>
                    </div>

                    <div class="vm-dash-rate">
                        <div class="vm-dash-rate__row">
                            <span class="vm-dash-muted">{{ t('dashboard.deliverability.bounce') }}</span>
                            <strong class="vm-dash-num" data-testid="deliverability-bounce">{{ format.percent(deliverability.bounceRate, 2) }}</strong>
                        </div>
                        <div class="vm-dash-bar"><i :style="{ inlineSize: fill(deliverability.bounceRate, deliverability.bounceThreshold) }" /></div>
                    </div>

                    <div class="vm-dash-rate">
                        <div class="vm-dash-rate__row">
                            <span class="vm-dash-muted">{{ t('dashboard.deliverability.complaint') }}</span>
                            <strong class="vm-dash-num" data-testid="deliverability-complaint">{{ format.percent(deliverability.complaintRate, 3) }}</strong>
                        </div>
                        <div class="vm-dash-bar"><i :style="{ inlineSize: fill(deliverability.complaintRate, deliverability.complaintThreshold) }" /></div>
                    </div>

                    <dl class="vm-dash-kv">
                        <template v-for="record in recordRows" :key="record.key">
                            <dt>{{ t(`dashboard.deliverability.${record.key}`) }}</dt>
                            <dd><VmStateBadge :state="record.state" :testid="`deliverability-${record.key}`" /></dd>
                        </template>
                    </dl>

                    <VtButton variant="secondary" size="sm" data-testid="dashboard-domains" @click="router.visit('/domains')">
                        {{ t('dashboard.deliverability.openDomains') }}
                    </VtButton>
                </VmCard>

            <!-- LEGUTÓBBI ÜZENETEK (napló) — teljes sor -->
            <VmCard class="vm-dash-full" data-dash-cell="recent">
                <h2>{{ t('dashboard.recent') }}</h2>

                <!-- A JOGOSULTSÁG HIÁNYA SZÖVEG, nem üres doboz: az `author` szerep szándékosan
                     nem kap `logs.view`-t, és ezt meg kell mondani, nem elhallgatni. -->
                <p v-if="delivery === null" data-testid="logs-forbidden">{{ t('dashboard.noAccess') }}</p>
                <p v-else-if="!rows.length" data-testid="recent-empty">{{ t('dashboard.empty') }}</p>
                <VmTable v-else :columns="columns" :rows="rows" :caption="t('dashboard.recent')" data-testid="recent-table">
                    <template #cell="{ column, value }">
                        <VmStateBadge v-if="column.key === 'status'" :state="String(value)" />
                        <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                    </template>
                </VmTable>
            </VmCard>
        </div>
    </AppLayout>
</template>

<style scoped>
.vm-dash-mode {
    display: flex;
    gap: var(--vt-space-3);
    align-items: flex-start;
    border-color: var(--vt-accent);
}

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
 | Keskeny rácsnál (2 / 1 sáv) a táblák RÖGZÍTETT elrendezésűek: az oszlopok a kártya szélességén
 | osztoznak, a hosszú érték levágva (`vm-truncate`) vagy tördelve — vízszintes túlcsordulás nincs.
 */
.vm-dash-grid--tight :deep(.vt-table__el) {
    table-layout: fixed;
}

.vm-dash-grid--tight :deep(.vm-truncate) {
    max-inline-size: 100%;
}

.vm-dash-grid--tight .vm-dash-campaign {
    min-inline-size: 0;
}

.vm-dash-span-3 {
    grid-column: span 3;
}

.vm-dash-span-1 {
    grid-column: span 1;
}

.vm-dash-full {
    grid-column: 1 / -1;
}

.vm-dash-kpi {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-dash-kpi__label,
.vm-dash-muted {
    color: var(--vt-text-secondary);
}

.vm-dash-kpi__value {
    font-family: var(--vt-font-display);
    font-size: var(--vt-type-display-m-size);
    color: var(--vt-text-primary);
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.vm-dash-kpi__trend {
    font-size: var(--vt-type-body-m-size);
    color: var(--vt-text-secondary);
}

.vm-dash-kpi__trend--up {
    color: var(--vt-status-success);
}

.vm-dash-kpi__trend--down {
    color: var(--vt-status-danger);
}

.vm-dash-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
}

.vm-dash-link {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-1);
    color: var(--vt-text-secondary);
}

.vm-dash-link__chevron {
    transform: rotate(-90deg);
}

.vm-dash-campaign {
    display: block;
    min-inline-size: calc(calc(var(--vt-space-12) * 2) * 1.25);
    color: var(--vt-text-primary);
    overflow-wrap: break-word;
}

.vm-dash-num {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.vm-dash-rate {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
}

.vm-dash-rate__row {
    display: flex;
    justify-content: space-between;
    gap: var(--vt-space-3);
}

.vm-dash-bar {
    block-size: var(--vt-space-1);
    border-radius: var(--vt-radius-full);
    background-color: var(--vt-surface-2);
    overflow: hidden;
}

.vm-dash-bar > i {
    display: block;
    block-size: 100%;
    background-color: var(--vt-accent);
}

.vm-dash-kv {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: var(--vt-space-3) var(--vt-space-4);
    align-items: center;
    margin: 0;
    padding-block-start: var(--vt-space-4);
    border-block-start: thin solid var(--vt-border);
}

.vm-dash-kv dd {
    margin: 0;
}

.vm-dash-todos {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-dash-todos li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2) var(--vt-space-3);
}

.vm-dash-todo__text {
    flex: 1 1 calc(calc(var(--vt-space-12) * 2) * 1.5);
    min-width: 0;
    overflow-wrap: anywhere;
}

.vm-dash-todo__icon--warning {
    color: var(--vt-status-warning);
}

.vm-dash-todo__icon--success {
    color: var(--vt-status-success);
}
</style>
