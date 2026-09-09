<script setup lang="ts">
import { VtCard, VtTable, type VtColumn } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

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
        checked: item.checkedAt ? new Date(item.checkedAt).toLocaleString() : '—',
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
        <h1 class="vt-text-display">{{ t('deliverability.title') }}</h1>

        <!-- A BLOCKLISTA az a hiba, amit MI veszünk észre utoljára: az ügyfélnél hirtelen
             senki nem kapja meg a levelet. -->
        <p v-if="blocklist.listedCount" class="vm-banner vm-banner--danger" role="status" data-testid="blocklist-warning">
            {{ t('deliverability.listedWarning', { count: blocklist.listedCount }) }}
        </p>

        <p v-if="unverified.length" class="vm-banner vm-banner--warning" role="status" data-testid="deliverability-unverified">
            {{ t('deliverability.unverified', { count: unverified.length }) }}
        </p>

        <VtCard>
            <h2>{{ t('deliverability.blocklist') }}</h2>
            <p v-if="!blocklistRows.length" data-testid="blocklist-empty">{{ t('deliverability.noChecks') }}</p>
            <VtTable
                v-else
                :columns="blocklistColumns"
                :rows="blocklistRows"
                :caption="t('deliverability.blocklist')"
                data-testid="blocklist-table"
            />
        </VtCard>

        <VtCard>
            <h2>{{ t('deliverability.dmarc') }}</h2>
            <p>{{ t('deliverability.dmarcHint') }}</p>
            <p v-if="!dmarcRows.length" data-testid="dmarc-empty">{{ t('deliverability.noDmarc') }}</p>
            <VtTable
                v-else
                :columns="dmarcColumns"
                :rows="dmarcRows"
                :caption="t('deliverability.dmarc')"
                data-testid="dmarc-table"
            />
        </VtCard>

        <VtCard>
            <h2>{{ t('deliverability.seed') }}</h2>
            <p v-if="!seedTests.length" data-testid="seed-empty">{{ t('deliverability.noSeed') }}</p>

            <div v-for="test in seedTests" :key="test.name" class="vm-cluster" :data-testid="`seed-${test.name}`">
                <strong>{{ test.name }}</strong>
                <span>{{ test.status }}</span>
                <!-- A HIÁNYZÓ is számít: a nevezőben benne van, különben a legrosszabb eset
                     kimaradna a statisztikából. -->
                <span>{{ t('deliverability.inbox') }}: {{ test.inboxRate }}%</span>
                <span>{{ t('deliverability.spam') }}: {{ test.spamRate }}%</span>
                <span>{{ t('deliverability.missing') }}: {{ test.missing }}</span>
            </div>
        </VtCard>
    </AppLayout>
</template>
