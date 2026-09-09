<script setup lang="ts">
import { VtCard, VtTable, type VtColumn } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../Layouts/AppLayout.vue';

/**
 * A NYITÓKÉPERNYŐ. Négy szám, ami MEGÁLLÍTJA a küldést, ha elromlik — nem üdvözlő szöveg.
 */
interface RecentMessage {
    uuid: string;
    subject: string;
    status: string;
    recipients: string[];
    created_at: string | null;
}

const props = defineProps<{
    quota: { remaining: number };
    domains: { total: number; verified: number; failed: number };
    suppressions: number;
    delivery: { delivered: number; opened: number; clicked: number; bounced: number; bounce_rate: number } | null;
    recent: RecentMessage[];
}>();

const { t } = useI18n();

// A HITELESÍTETLEN DOMAIN nem statisztika, hanem AKADÁLY: a PreSendGate 1. kapuja tiltja
// a küldést róla. Ezért figyelmeztetés, nem egy szám a többi között.
const hasUnverified = computed(() => props.domains.total > props.domains.verified);

type Row = { subject: string; status: string; recipient: string; created: string };

const columns: VtColumn<Row>[] = [
    { key: 'created', label: 'Időpont' },
    { key: 'subject', label: 'Tárgy' },
    { key: 'recipient', label: 'Címzett' },
    { key: 'status', label: 'Állapot' },
];

const rows = computed<Row[]>(() =>
    props.recent.map((message) => ({
        subject: message.subject,
        status: message.status,
        recipient: message.recipients[0] ?? '—',
        created: message.created_at ? new Date(message.created_at).toLocaleString() : '—',
    })),
);
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('dashboard.title') }}</h1>

        <p v-if="hasUnverified" class="vm-banner vm-banner--warning" role="status" data-testid="unverified-warning">
            {{ t('dashboard.unverifiedWarning') }}
        </p>

        <div class="vm-metrics" data-testid="dashboard-metrics">
            <VtCard>
                <p class="vm-metric__label">{{ t('dashboard.quota') }}</p>
                <p class="vm-metric__value" data-testid="metric-quota">{{ quota.remaining.toLocaleString() }}</p>
            </VtCard>

            <VtCard>
                <p class="vm-metric__label">{{ t('dashboard.domains') }}</p>
                <p class="vm-metric__value" data-testid="metric-domains">{{ domains.verified }} / {{ domains.total }}</p>
            </VtCard>

            <VtCard>
                <p class="vm-metric__label">{{ t('dashboard.suppressions') }}</p>
                <p class="vm-metric__value" data-testid="metric-suppressions">{{ suppressions.toLocaleString() }}</p>
            </VtCard>

            <VtCard v-if="delivery">
                <p class="vm-metric__label">{{ t('dashboard.bounceRate') }}</p>
                <p class="vm-metric__value" data-testid="metric-bounce-rate">{{ delivery.bounce_rate }}%</p>
            </VtCard>
        </div>

        <VtCard v-if="delivery" data-testid="delivery-summary">
            <div class="vm-cluster">
                <span>{{ t('dashboard.delivered') }}: <strong>{{ delivery.delivered }}</strong></span>
                <span>{{ t('dashboard.opened') }}: <strong>{{ delivery.opened }}</strong></span>
                <span>{{ t('dashboard.clicked') }}: <strong>{{ delivery.clicked }}</strong></span>
                <span>{{ t('dashboard.bounced') }}: <strong>{{ delivery.bounced }}</strong></span>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('dashboard.recent') }}</h2>

            <!-- A JOGOSULTSÁG HIÁNYA SZÖVEG, nem üres doboz: az `author` szerep szándékosan
                 nem kap `logs.view`-t, és ezt meg kell mondani, nem elhallgatni. -->
            <p v-if="delivery === null" data-testid="logs-forbidden">{{ t('dashboard.noAccess') }}</p>
            <p v-else-if="!rows.length" data-testid="recent-empty">{{ t('dashboard.empty') }}</p>
            <VtTable v-else :columns="columns" :rows="rows" :caption="t('dashboard.recent')" data-testid="recent-table" />
        </VtCard>
    </AppLayout>
</template>
