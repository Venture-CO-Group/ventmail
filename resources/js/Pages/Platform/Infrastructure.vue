<script setup lang="ts">
import { VtBanner, VtPageHeader, type VtColumn } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmStat from '../../Components/Form/VmStat.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmStateBadge from '../../Components/Platform/VmStateBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * KÜLDÉSI INFRASTRUKTÚRA (v1.1 · 7. modul, D176) — csak platform-adminnak, CSAK OLVASÁS.
 *
 * Poolok, IP-k reputációval és warmuppal, a legutóbbi blocklist-állapot, a rate limitek és a
 * küldési mód kapcsolói. Állítani innen semmit nem lehet: a karantén a konzolon, a küldési mód
 * a tulajdonosé (RUNBOOK-mail §13).
 */
interface Address {
    address: string;
    hostname: string;
    pool: string;
    pool_type: string;
    status: string;
    warmup_state: string;
    daily_limit: number;
    sent_today: number;
    utilisation: number | null;
    reputation_score: number | null;
    quarantined_at: string | null;
    quarantine_reason: string | null;
    blocklist: Array<{ zone: string; listed: boolean; checkedAt: string | null }>;
}

const props = defineProps<{
    pools: Array<{ uuid: string; name: string; type: string; channel: string | null; workspace: string | null; addresses: number }>;
    addresses: Address[];
    rateLimits: { workspacePerMinute: number; smtpCredentialPerMinute: number; receiverDefault: number; receivers: Array<{ domain: string; perMinute: number }> };
    sendingMode: { live: boolean; marketingEnabled: boolean; allowlistEnforced: boolean; allowlistSize: number };
}>();

const { t } = useI18n();
const format = useFormat();

type PoolRow = { name: string; type: string; channel: string; owner: string; addresses: string };

const poolColumns = computed<VtColumn<PoolRow>[]>(() => [
    { key: 'name', label: t('ipPools.pool') },
    { key: 'type', label: t('plat.infra.type') },
    { key: 'channel', label: t('logs.channel') },
    { key: 'owner', label: t('plat.infra.owner') },
    { key: 'addresses', label: t('plat.infra.addresses'), align: 'right' },
]);

const poolRows = computed<PoolRow[]>(() =>
    props.pools.map((pool) => ({
        name: pool.name,
        type: pool.type,
        channel: pool.channel ?? t('plat.infra.anyChannel'),
        owner: pool.workspace ?? t('ipPools.shared'),
        addresses: String(pool.addresses),
    })),
);

type IpRow = { address: string; pool: string; status: string; warmup: string; usage: string; reputation: string; blocklist: string };

const ipColumns = computed<VtColumn<IpRow>[]>(() => [
    { key: 'address', label: t('ipPools.address'), mono: true },
    { key: 'pool', label: t('ipPools.pool') },
    { key: 'status', label: t('ipPools.state') },
    { key: 'warmup', label: t('plat.infra.warmup') },
    { key: 'usage', label: t('ipPools.usage'), align: 'right', mono: true },
    { key: 'reputation', label: t('ipPools.reputation'), align: 'right', mono: true },
    { key: 'blocklist', label: t('plat.infra.blocklist') },
]);

const byAddress = computed(() => new Map(props.addresses.map((ip) => [ip.address, ip])));

const ipRows = computed<IpRow[]>(() =>
    props.addresses.map((ip) => ({
        address: ip.address,
        pool: ip.pool,
        status: ip.status,
        warmup: ip.warmup_state,
        usage: `${format.number(ip.sent_today)} / ${format.number(ip.daily_limit)}${ip.utilisation === null ? '' : ` · ${ip.utilisation}%`}`,
        reputation: ip.reputation_score === null ? '—' : ip.reputation_score.toFixed(2),
        blocklist: ip.blocklist.some((check) => check.listed) ? 'listed' : ip.blocklist.length ? 'clean' : 'unknown',
    })),
);

const listed = computed(() => props.addresses.filter((ip) => ip.blocklist.some((check) => check.listed)));
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('plat.eyebrow.infra')" :title="t('plat.infra.title')" :description="t('plat.infra.lead')" />

        <VtBanner v-if="listed.length" tone="danger" role="status" data-testid="infra-listed">
            {{ t('plat.infra.listedWarning', { count: listed.length }) }}
        </VtBanner>

        <div class="vm-tiles" data-testid="infra-mode">
            <VmStat :label="t('plat.infra.mode.live')" :value="sendingMode.live ? t('plat.infra.on') : t('plat.infra.off')" :tone="sendingMode.live ? 'success' : 'warning'" />
            <VmStat :label="t('plat.infra.mode.allowlist')" :value="sendingMode.allowlistEnforced ? t('plat.infra.on') : t('plat.infra.off')" :hint="t('plat.infra.mode.allowlistSize', { count: sendingMode.allowlistSize })" />
            <VmStat :label="t('plat.infra.mode.marketing')" :value="sendingMode.marketingEnabled ? t('plat.infra.on') : t('plat.infra.off')" />
            <VmStat :label="t('plat.infra.rate.workspace')" :value="rateLimits.workspacePerMinute" :hint="t('plat.infra.perMinute')" />
            <VmStat :label="t('plat.infra.rate.smtp')" :value="rateLimits.smtpCredentialPerMinute" :hint="t('plat.infra.perMinute')" />
        </div>

        <VmCard :title="t('ipPools.pools')">
            <VmTable :columns="poolColumns" :rows="poolRows" :caption="t('ipPools.pools')" data-testid="infra-pools" />
        </VmCard>

        <VmCard :title="t('plat.infra.ips')">
            <p v-if="!ipRows.length">{{ t('plat.infra.noIps') }}</p>
            <VmTable v-else :columns="ipColumns" :rows="ipRows" :caption="t('plat.infra.ips')" data-testid="infra-ips">
                <template #cell="{ row, column, value }">
                    <VmStateBadge v-if="column.key === 'status' || column.key === 'warmup'" :state="String(value)" />
                    <span
                        v-else-if="column.key === 'blocklist'"
                        :title="(byAddress.get(row.address)?.blocklist ?? []).map((check) => `${check.zone}: ${check.listed ? 'listed' : 'ok'}`).join(', ')"
                    >
                        <VmStateBadge :state="String(value)" :testid="`blocklist-${row.address}`" />
                    </span>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>
        </VmCard>

        <VmCard :title="t('plat.infra.receivers')">
            <dl class="vm-kv" data-testid="infra-receivers">
                <template v-for="receiver in rateLimits.receivers" :key="receiver.domain">
                    <dt class="vt-mono">{{ receiver.domain }}</dt>
                    <dd>{{ t('plat.infra.perMinuteN', { n: receiver.perMinute }) }}</dd>
                </template>
                <dt>{{ t('plat.infra.otherReceivers') }}</dt>
                <dd>{{ t('plat.infra.perMinuteN', { n: rateLimits.receiverDefault }) }}</dd>
            </dl>
            <p class="vm-muted">{{ t('plat.infra.readOnly') }}</p>
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(calc(var(--vt-space-8) * 4) * 1.5)), 1fr));
    gap: var(--vt-space-4);
}

.vm-kv {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--vt-space-2) var(--vt-space-6);
    margin: 0;
}

.vm-kv dd {
    margin: 0;
}

.vm-muted {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}
</style>
