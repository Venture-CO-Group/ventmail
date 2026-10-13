<script setup lang="ts">
import { type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useCodes } from '../../composables/useCodes';

/**
 * IP-POOLOK ÉS WARMUP — olvasható, mert a karantén platform-admin művelet.
 *
 * A kihasználtság az, amit ránézésre keresünk: ha a napi keret elfogy, a levél nem
 * elveszik, hanem CSÚSZIK — és ezt a küldő magától nem tudná megmagyarázni.
 */
interface Address {
    address: string;
    hostname: string;
    pool: string;
    status: string;
    warmupState: string;
    dailyLimit: number;
    sentToday: number;
    utilisation: number | null;
    reputation: number | null;
    quarantinedAt: string | null;
    quarantineReason: string | null;
}

const props = defineProps<{
    pools: Array<{ uuid: string; name: string; type: string; shared: boolean }>;
    addresses: Address[];
}>();

const { t } = useI18n();
const code = useCodes();

const quarantined = computed(() => props.addresses.filter((ip) => ip.quarantinedAt !== null));
const warming = computed(() => props.addresses.filter((ip) => ip.warmupState !== 'complete'));

type Row = { address: string; pool: string; state: string; usage: string; reputation: string };

const columns: VtColumn<Row>[] = [
    { key: 'address', label: t('ipPools.address'), mono: true },
    { key: 'pool', label: t('ipPools.pool') },
    { key: 'state', label: t('ipPools.state') },
    { key: 'usage', label: t('ipPools.usage') },
    { key: 'reputation', label: t('ipPools.reputation') },
];

const rows = computed<Row[]>(() =>
    props.addresses.map((ip) => ({
        address: ip.address,
        pool: ip.pool,
        state: ip.quarantinedAt ? t('ipPools.quarantined') : `${ip.status} · ${ip.warmupState}`,
        // A NYERS SZÁMOT is kiírjuk a százalék mellé: a „78%" önmagában nem mondja meg,
        // hogy 780/1000 vagy 7800/10000 — a warmup-görbénél ez a különbség.
        usage: ip.utilisation === null ? '—' : `${ip.utilisation}% (${ip.sentToday}/${ip.dailyLimit})`,
        reputation: ip.reputation === null ? '—' : String(ip.reputation),
    })),
);
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('ipPools.title') }}</h1>

        <p v-if="quarantined.length" class="vm-banner vm-banner--danger" role="status" data-testid="ip-quarantined">
            {{ t('ipPools.quarantinedWarning', { count: quarantined.length }) }}
        </p>

        <p v-if="warming.length" class="vm-banner vm-banner--warning" role="status" data-testid="ip-warming">
            {{ t('ipPools.warmupNote', { count: warming.length }) }}
        </p>

        <VmCard>
            <h2>{{ t('ipPools.pools') }}</h2>

            <div v-for="pool in pools" :key="pool.uuid" class="vm-cluster" :data-testid="`pool-${pool.uuid}`">
                <strong>{{ pool.name }}</strong>
                <span>{{ code('poolType', pool.type) }}</span>
                <!-- A MEGOSZTOTT pool reputációját mások forgalma is alakítja — ezt tudni kell. -->
                <span>{{ pool.shared ? t('ipPools.shared') : t('ipPools.dedicated') }}</span>
            </div>
        </VmCard>

        <VmCard>
            <p v-if="!rows.length" data-testid="ips-empty">{{ t('ipPools.empty') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('ipPools.title')" data-testid="ips-table" />
        </VmCard>
    </AppLayout>
</template>
