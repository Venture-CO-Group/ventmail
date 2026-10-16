<script setup lang="ts">
import { VtBanner, VtEmptyState, VtPageHeader, type VtColumn } from '@venture-co-group/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * KÉZBESÍTHETŐSÉG — a három jel, ami elárulja, hogy baj lesz, mielőtt az ügyfél telefonál.
 */
const props = defineProps<{
    blocklist: {
        listedCount: number;
        items: Array<{ address: string; zone: string; listed: boolean; detail: string | null; checkedAt: string | null }>;
    };
    dmarc: Array<{ domain: string; org: string; policy: string; total: number; passed: number; failed: number; failRate: number; rangeEnd: string | null }>;
    seedTests: Array<{ name: string; status: string; inboxRate: number; spamRate: number; missing: number; finishedAt: string | null }>;
    domains: Array<{ name: string; status: string; dmarc: string }>;
}>();

const { t } = useI18n();
const code = useCodes();
const format = useFormat();

const unverified = computed(() => props.domains.filter((domain) => domain.status !== 'verified'));

type BlocklistRow = { address: string; zone: string; listed: string; checked: string };

const blocklistColumns: VtColumn<BlocklistRow>[] = [
    { key: 'address', label: t('deliverability.ip'), mono: true },
    { key: 'zone', label: t('deliverability.zone') },
    { key: 'listed', label: t('deliverability.listed') },
    { key: 'checked', label: t('deliverability.checked') },
];

const blocklistRows = computed<BlocklistRow[]>(() =>
    props.blocklist.items.map((item) => ({
        address: item.address,
        zone: item.zone,
        listed: item.listed ? t('deliverability.yes') : t('deliverability.no'),
        checked: item.checkedAt ? format.dateTime(item.checkedAt) : '—',
    })),
);

type DmarcRow = { domain: string; org: string; policy: string; total: string; failRate: string };

const dmarcColumns: VtColumn<DmarcRow>[] = [
    { key: 'domain', label: t('deliverability.domain') },
    { key: 'org', label: t('deliverability.reporter') },
    { key: 'policy', label: t('deliverability.policy') },
    { key: 'total', label: t('deliverability.messages') },
    { key: 'failRate', label: t('deliverability.failRate') },
];

const dmarcRows = computed<DmarcRow[]>(() =>
    props.dmarc.map((report) => ({
        domain: report.domain,
        org: report.org,
        policy: report.policy,
        total: String(report.total),
        failRate: `${report.failRate}%`,
    })),
);
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('nav.platform')" :title="t('deliverability.title')" :description="t('page.deliverability.lead')" />

        <!-- A BLOCKLISTA az a hiba, amit MI veszünk észre utoljára: az ügyfélnél hirtelen
             senki nem kapja meg a levelet. -->
        <VtBanner v-if="blocklist.listedCount" tone="danger" role="status" data-testid="blocklist-warning">
            {{ t('deliverability.listedWarning', { count: blocklist.listedCount }) }}
        </VtBanner>

        <VtBanner v-if="unverified.length" tone="warning" role="status" data-testid="deliverability-unverified">
            {{ t('deliverability.unverified', { count: unverified.length }) }}
        </VtBanner>

        <VmCard :title="t('deliverability.blocklist')">
            <VtEmptyState v-if="!blocklistRows.length" :title="t('deliverability.noChecks')" icon="inbox" data-testid="blocklist-empty" />
            <VmTable
                v-else
                :columns="blocklistColumns"
                :rows="blocklistRows"
                :caption="t('deliverability.blocklist')"
                data-testid="blocklist-table"
            />
        </VmCard>

        <VmCard :title="t('deliverability.dmarc')">
            <p>{{ t('deliverability.dmarcHint') }}</p>
            <VtEmptyState v-if="!dmarcRows.length" :title="t('deliverability.noDmarc')" icon="inbox" data-testid="dmarc-empty" />
            <VmTable
                v-else
                :columns="dmarcColumns"
                :rows="dmarcRows"
                :caption="t('deliverability.dmarc')"
                data-testid="dmarc-table"
            />
        </VmCard>

        <VmCard :title="t('deliverability.seed')">
            <VtEmptyState v-if="!seedTests.length" :title="t('deliverability.noSeed')" icon="inbox" data-testid="seed-empty" />

            <div v-for="test in seedTests" :key="test.name" class="vm-cluster" :data-testid="`seed-${test.name}`">
                <strong>{{ test.name }}</strong>
                <span :data-status="test.status">{{ code('status', test.status) }}</span>
                <!-- A HIÁNYZÓ is számít: a nevezőben benne van, különben a legrosszabb eset
                     kimaradna a statisztikából. -->
                <span>{{ t('deliverability.inbox') }}: {{ test.inboxRate }}%</span>
                <span>{{ t('deliverability.spam') }}: {{ test.spamRate }}%</span>
                <span>{{ t('deliverability.missing') }}: {{ test.missing }}</span>
            </div>
        </VmCard>
    </AppLayout>
</template>
