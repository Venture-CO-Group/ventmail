<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { VtBanner, VtButton, VtDataTable, VtEmptyState, VtModal, VtPageHeader, type VtDataColumn } from '@venture-co-group/ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmCopyButton from '../../Components/Platform/VmCopyButton.vue';
import VmSplit from '../../Components/Platform/VmSplit.vue';
import VmStateBadge from '../../Components/Platform/VmStateBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * KÜLDŐ DOMAINEK (v1.1 · 7. modul).
 *
 * Bal oldalt a domainek („N / 5 rekord rendben"), jobbra a kiválasztott domain MÁSOLHATÓ
 * DNS-rekordjai élő állapottal (SPF, DKIM, DMARC, Return-Path, tracking CNAME) és
 * újraellenőrzéssel; alatta a DKIM-kulcs és a rotáció.
 *
 * Az állapot ÉLŐ: amíg a domain nincs hitelesítve, a képernyő percenként újraolvassa a
 * szerver állapotát (a háttér-ellenőrzés eredményét) — a „Újraellenőrzés" gomb pedig
 * azonnal lefuttatja a DNS-lekérdezést.
 */
interface DomainRow {
    uuid: string;
    name: string;
    status: string;
    spf: string;
    dkim: string;
    dmarc: string;
    tracking: string;
    returnPath: string;
    verified_at: string | null;
    okCount: number;
}

type DnsRecordRow = {
    key: string;
    type: string;
    name: string;
    value: string;
    status: string;
    optional: boolean;
};

interface Selected {
    uuid: string;
    name: string;
    status: string;
    lastCheckedAt: string | null;
    records: DnsRecordRow[];
    dkim: {
        selector: string;
        signingSelector: string;
        previousSelector: string | null;
        rotatedAt: string | null;
        previousValidUntil: string | null;
        overlapDays: number;
    };
}

const props = defineProps<{ domains: DomainRow[]; selected: Selected | null }>();

const { t } = useI18n();
const format = useFormat();

const checking = ref(false);
const rotating = ref(false);
const confirmRotate = ref(false);

const unverified = computed(() => props.domains.filter((domain) => domain.status !== 'verified'));
const pendingOptional = computed(() =>
    (props.selected?.records ?? []).filter((record) => record.key === 'tracking' && record.status !== 'verified'),
);

function when(iso: string | null): string {
    return iso ? format.dateTime(iso) : '—';
}

const dnsColumns = computed<VtDataColumn<DnsRecordRow>[]>(() => [
    { key: 'key', label: t('plat.domains.purpose'), width: '17%' },
    { key: 'type', label: t('wizard.type'), width: '9%', mono: true },
    { key: 'name', label: t('wizard.name'), width: '27%' },
    { key: 'value', label: t('wizard.value'), width: '32%' },
    { key: 'status', label: t('wizard.rowStatus'), width: '15%' },
]);

function recordLabel(key: string): string {
    return t(`plat.domains.record.${key}`);
}

function recordState(record: DnsRecordRow): string {
    if (record.status === 'verified' || record.status === 'rotating') return record.status;

    if (record.optional) return 'optional';

    return record.status === 'pending' ? 'propagating' : record.status;
}

function recheck(): void {
    if (!props.selected) return;

    checking.value = true;
    router.post(`/domains/${props.selected.uuid}/verify`, { return: 'index' }, {
        preserveScroll: true,
        onFinish: () => (checking.value = false),
    });
}

function rotate(): void {
    if (!props.selected) return;

    rotating.value = true;
    router.post(`/domains/${props.selected.uuid}/dkim/rotate`, { return: 'index' }, {
        preserveScroll: true,
        onFinish: () => {
            rotating.value = false;
            confirmRotate.value = false;
        },
    });
}

/*
 | ÉLŐ ÁLLAPOT: amíg a kiválasztott domain nincs kész, percenként újraolvassuk (csak a két
 | propot, és csak látható fülön) — a háttérben futó ellenőrzés eredménye így magától megjelenik.
 */
let poll: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    poll = setInterval(() => {
        if (document.hidden || !props.selected || props.selected.status === 'verified') return;

        router.reload({ only: ['domains', 'selected'] });
    }, 60_000);
});

onBeforeUnmount(() => {
    if (poll) clearInterval(poll);
});
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('nav.platform')" :title="t('plat.domains.title')" :description="t('page.domains.lead')">
            <template #actions>
                <Link href="/domains/wizard">
                    <VtButton data-testid="domains-add">{{ t('domains.add') }}</VtButton>
                </Link>
            </template>
        </VtPageHeader>

        <!-- A HITELESÍTETLEN DOMAIN nem statisztika, hanem akadály: a PreSendGate 1. kapuja
             tiltja a küldést róla. Ezért figyelmeztetés, nem egy sor a többi között. -->
        <VtBanner v-if="unverified.length" tone="warning" role="status" data-testid="domains-unverified">
            {{ t('domains.unverified', { count: unverified.length }) }}
        </VtBanner>

        <VmCard v-if="!domains.length">
            <VtEmptyState :title="t('domains.empty')" icon="inbox" data-testid="domains-empty" />
        </VmCard>

        <VmSplit v-else>
            <template #side>
                <nav class="vm-domain-list" :aria-label="t('plat.domains.title')" data-testid="domains-table">
                    <Link
                        v-for="domain in domains"
                        :key="domain.uuid"
                        :href="`/domains?domain=${domain.uuid}`"
                        preserve-scroll
                        class="vm-domain-item"
                        :class="{ 'vm-domain-item--active': selected?.uuid === domain.uuid }"
                        :aria-current="selected?.uuid === domain.uuid ? 'true' : undefined"
                        :data-testid="`domain-item-${domain.name}`"
                    >
                        <span class="vm-domain-item__text">
                            <strong class="vm-truncate" :title="domain.name">{{ domain.name }}</strong>
                            <span class="vm-domain-item__meta">{{ t('plat.domains.okCount', { ok: domain.okCount, total: 5 }) }}</span>
                        </span>
                        <VmStateBadge :state="domain.status" />
                    </Link>
                </nav>

            </template>

            <VmCard v-if="selected" data-testid="domain-detail">
                <div class="vm-detail-head">
                    <div class="vm-detail-head__text">
                        <h2 class="vm-wrap" data-testid="domain-name">{{ selected.name }}</h2>
                        <p class="vm-muted">
                            {{ t('plat.domains.lastChecked', { at: when(selected.lastCheckedAt) }) }}
                        </p>
                    </div>
                    <VtButton variant="secondary" data-testid="verify-button" :disabled="checking" @click="recheck">
                        {{ t('plat.domains.recheck') }}
                    </VtButton>
                </div>

                <VtBanner tone="warning" role="status" v-if="pendingOptional.length" data-testid="tracking-pending">
                    {{ t('plat.domains.trackingPending') }}
                </VtBanner>

                <!-- A DNS-rekordok a közös DataTable-ben (5. szabály); a név és az érték NEM vágható (D284). -->
                <VtDataTable class="vm-dns" :columns="dnsColumns" :rows="selected.records" row-key="key" :caption="t('wizard.dnsCaption')" data-testid="dns-records">
                    <template #cell="{ row, column }">
                        <strong v-if="column.key === 'key'" :data-testid="`dns-row-${row.key}`">{{ recordLabel(row.key) }}</strong>
                        <span v-else-if="column.key === 'name' || column.key === 'value'" class="vm-dns__cell">
                            <code>{{ row[column.key] }}</code>
                            <VmCopyButton :value="row[column.key]" :testid="`copy-${column.key}-${row.key}`" icon-only />
                        </span>
                        <VmStateBadge v-else-if="column.key === 'status'" :state="recordState(row)" :testid="`dns-state-${row.key}`" />
                        <span v-else>{{ row[column.key] }}</span>
                    </template>
                </VtDataTable>

                <VmFormActions>
                    <Link :href="`/domains/wizard?domain=${selected.uuid}`" class="vm-link">{{ t('plat.domains.allRecords') }}</Link>
                </VmFormActions>
            </VmCard>

            <VmCard v-if="selected" data-testid="dkim-card">
                <h2 class="vm-eyebrow">{{ t('plat.domains.dkimTitle') }}</h2>
                <dl class="vm-kv">
                    <dt>{{ t('plat.domains.activeSelector') }}</dt>
                    <dd class="vt-mono" data-testid="dkim-selector">{{ selected.dkim.signingSelector }}</dd>
                    <dt>{{ t('plat.domains.publishedSelector') }}</dt>
                    <dd class="vt-mono">{{ selected.dkim.selector }}</dd>
                    <dt>{{ t('plat.domains.rotatedAt') }}</dt>
                    <dd>{{ when(selected.dkim.rotatedAt) }}</dd>
                    <template v-if="selected.dkim.previousSelector">
                        <dt>{{ t('plat.domains.previousSelector') }}</dt>
                        <dd class="vt-mono">{{ selected.dkim.previousSelector }} · {{ when(selected.dkim.previousValidUntil) }}</dd>
                    </template>
                </dl>
                <VtButton variant="secondary" data-testid="rotate-button" :disabled="rotating" @click="confirmRotate = true">
                    {{ t('wizard.rotate') }}
                </VtButton>
                <p class="vm-muted">{{ t('plat.domains.rotateHint', { days: selected.dkim.overlapDays }) }}</p>
            </VmCard>
        </VmSplit>

        <VtModal :open="confirmRotate" :title="t('wizard.rotate')" @close="confirmRotate = false">
            <p>{{ t('plat.domains.rotateConfirm', { days: selected?.dkim.overlapDays ?? 0 }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="confirmRotate = false">{{ t('plat.cancel') }}</VtButton>
                <VtButton data-testid="rotate-confirm" :disabled="rotating" @click="rotate">{{ t('wizard.rotate') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-domain-list {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    max-height: calc(calc(var(--vt-space-8) * 4) * 6);
    overflow-y: auto;
}

.vm-domain-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
    padding: var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-lg);
    background: var(--vt-bg-raised);
    color: var(--vt-text-primary);
    text-decoration: none;
    min-width: 0;
}

.vm-domain-item--active {
    border-color: var(--vt-accent);
}

.vm-domain-item:focus-visible {
    outline: thin solid var(--vt-accent);
}

.vm-domain-item__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-domain-item__meta,
.vm-muted {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}

.vm-eyebrow {
    margin: 0;
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
}

.vm-kv {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--vt-space-2) var(--vt-space-4);
    margin: 0;
}

.vm-kv dt {
    color: var(--vt-text-secondary);
}

.vm-kv dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.vm-detail-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-detail-head__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-detail-head__text h2,
.vm-detail-head__text p {
    margin: 0;
}

.vm-scroll-x {
    /* `relative`: a látássérült-csak felirat (abszolút) is a görgető dobozban marad. */
    position: relative;
    overflow-x: auto;
    min-width: 0;
    max-width: 100%;
}

/* Rögzített oszlopok: a hosszú DKIM-kulcs tördel, nem tolja szét a táblát. */
.vm-dns :deep(.vt-dt__el) {
    table-layout: fixed;
    min-width: calc(calc(var(--vt-space-8) * 4) * 6);
}

.vm-dns__cell {
    display: flex;
    align-items: flex-start;
    gap: var(--vt-space-2);
    min-width: 0;
}

/*
 | D284: a DNS-név és -érték NEM vágható le („demo.peld…") — a felhasználó szemmel is ellenőrzi,
 | mit vesz fel a szolgáltatónál. A DKIM-kulcs egyetlen hosszú „szó", ezért bárhol törhet.
 */
.vm-dns__cell code {
    flex: 1 1 auto;
    min-width: 0;
    font-family: var(--vt-font-mono);
    font-size: var(--vt-type-body-m-size);
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-all;
}
</style>
