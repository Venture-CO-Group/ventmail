<script setup lang="ts">
import { VtBanner, VtEmptyState, VtKpiCard, VtPageHeader, type VtColumn } from '@venture-co-group/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

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
const format = useFormat();

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
        due: invoice.dueAt ? format.date(invoice.dueAt) : '—',
        paid: invoice.paidAt ? format.date(invoice.paidAt) : '—',
    })),
);

const enabledFeatures = computed(() => props.features.filter((feature) => feature.enabled));
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('nav.platform')" :title="t('billing.title')" :description="t('page.billing.lead')" />

        <!-- A FELFÜGGESZTÉS nem lehet meglepetés: a PreSendGate 4. kapuja
             `QUOTA_EXCEEDED`-et ad, és ha ezt csak a hibaüzenetből tudná meg az ügyfél, a
             támogatás értesülne róla először. -->
        <VtBanner v-if="suspended" tone="danger" role="status" data-testid="billing-suspended">
            {{ t('billing.suspendedWarning') }}
        </VtBanner>

        <div class="vm-metrics">
            <VtKpiCard :label="t('billing.remainingQuota')" :value="format.number(remainingQuota)" data-testid="billing-quota" />
            <VtKpiCard :label="t('billing.currentPeriod')" :value="period" data-testid="billing-period" />
        </div>

        <VmCard :title="t('billing.usage')">
            <div v-for="item in usage" :key="item.dimension" class="vm-cluster" :data-testid="`usage-${item.dimension}`">
                <span>{{ item.dimension }}</span>
                <strong>{{ format.number(item.current) }}</strong>
            </div>
        </VmCard>

        <VmCard :title="t('billing.features')">
            <VtEmptyState v-if="!enabledFeatures.length" :title="t('billing.noFeatures')" icon="inbox" data-testid="features-empty" />

            <div class="vm-cluster">
                <span v-for="feature in features" :key="feature.feature" :data-testid="`feature-${feature.feature}`">
                    {{ feature.feature }}: {{ feature.enabled ? t('billing.on') : t('billing.off') }}
                </span>
            </div>
        </VmCard>

        <VmCard :title="t('billing.invoices')">
            <VtEmptyState v-if="!rows.length" :title="t('billing.noInvoices')" icon="inbox" data-testid="invoices-empty" />
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('billing.invoices')" data-testid="invoices-table" />
        </VmCard>
    </AppLayout>
</template>
