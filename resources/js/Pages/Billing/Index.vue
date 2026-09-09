<script setup lang="ts">
import { VtCard, VtTable, type VtColumn } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * SZÁMLÁZÁS ÉS FOGYÁS. A felfüggesztés itt LÁTSZIK, mielőtt a küldés leáll — különben a
 * támogatás értesülne róla először.
 */
const props = defineProps<{
    period: string;
    usage: Array<{ dimension: string; current: number }>;
    features: Array<{ feature: string; enabled: boolean }>;
    remainingQuota: number;
    suspended: boolean;
    invoices: Array<{
        uuid: string;
        period: string;
        state: string;
        dueAt: string | null;
        paidAt: string | null;
        suspendedAt: string | null;
        closedAt: string | null;
        usage: Record<string, number>;
    }>;
}>();

const { t } = useI18n();

type Row = { period: string; state: string; due: string; paid: string };

const columns: VtColumn<Row>[] = [
    { key: 'period', label: t('billing.period') },
    { key: 'state', label: t('billing.state') },
    { key: 'due', label: t('billing.due') },
    { key: 'paid', label: t('billing.paid') },
];

const rows = computed<Row[]>(() =>
    props.invoices.map((invoice) => ({
        period: invoice.period,
        state: invoice.state,
        due: invoice.dueAt ? new Date(invoice.dueAt).toLocaleDateString() : '—',
        paid: invoice.paidAt ? new Date(invoice.paidAt).toLocaleDateString() : '—',
    })),
);

const enabledFeatures = computed(() => props.features.filter((feature) => feature.enabled));
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('billing.title') }}</h1>

        <!-- A FELFÜGGESZTÉS nem lehet meglepetés: a PreSendGate 4. kapuja
             `QUOTA_EXCEEDED`-et ad, és ha ezt csak a hibaüzenetből tudná meg az ügyfél, a
             támogatás értesülne róla először. -->
        <p v-if="suspended" class="vm-banner vm-banner--danger" role="status" data-testid="billing-suspended">
            {{ t('billing.suspendedWarning') }}
        </p>

        <div class="vm-metrics">
            <VtCard>
                <p class="vm-metric__label">{{ t('billing.remainingQuota') }}</p>
                <p class="vm-metric__value" data-testid="billing-quota">{{ remainingQuota.toLocaleString() }}</p>
            </VtCard>

            <VtCard>
                <p class="vm-metric__label">{{ t('billing.currentPeriod') }}</p>
                <p class="vm-metric__value" data-testid="billing-period">{{ period }}</p>
            </VtCard>
        </div>

        <VtCard>
            <h2>{{ t('billing.usage') }}</h2>

            <div v-for="item in usage" :key="item.dimension" class="vm-cluster" :data-testid="`usage-${item.dimension}`">
                <span>{{ item.dimension }}</span>
                <strong>{{ item.current.toLocaleString() }}</strong>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('billing.features') }}</h2>
            <p v-if="!enabledFeatures.length" data-testid="features-empty">{{ t('billing.noFeatures') }}</p>

            <div class="vm-cluster">
                <span v-for="feature in features" :key="feature.feature" :data-testid="`feature-${feature.feature}`">
                    {{ feature.feature }}: {{ feature.enabled ? t('billing.on') : t('billing.off') }}
                </span>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('billing.invoices') }}</h2>
            <p v-if="!rows.length" data-testid="invoices-empty">{{ t('billing.noInvoices') }}</p>
            <VtTable v-else :columns="columns" :rows="rows" :caption="t('billing.invoices')" data-testid="invoices-table" />
        </VtCard>
    </AppLayout>
</template>
