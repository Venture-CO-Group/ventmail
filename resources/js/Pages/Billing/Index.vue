<script setup lang="ts">
import { VtBanner, type VtColumn } from '@venture-co-group/ui';
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
        <h1 class="vt-num">{{ t('billing.title') }}</h1>

        <!-- A FELFÜGGESZTÉS nem lehet meglepetés: a PreSendGate 4. kapuja
             `QUOTA_EXCEEDED`-et ad, és ha ezt csak a hibaüzenetből tudná meg az ügyfél, a
             támogatás értesülne róla először. -->
        <VtBanner v-if="suspended" tone="danger" role="status" data-testid="billing-suspended">
            {{ t('billing.suspendedWarning') }}
        </VtBanner>

        <div class="vm-metrics">
            <VmCard>
                <p class="vm-metric__label">{{ t('billing.remainingQuota') }}</p>
                <p class="vm-metric__value" data-testid="billing-quota">{{ format.number(remainingQuota) }}</p>
            </VmCard>

            <VmCard>
                <p class="vm-metric__label">{{ t('billing.currentPeriod') }}</p>
                <p class="vm-metric__value" data-testid="billing-period">{{ period }}</p>
            </VmCard>
        </div>

        <VmCard>
            <h2>{{ t('billing.usage') }}</h2>

            <div v-for="item in usage" :key="item.dimension" class="vm-cluster" :data-testid="`usage-${item.dimension}`">
                <span>{{ item.dimension }}</span>
                <strong>{{ format.number(item.current) }}</strong>
            </div>
        </VmCard>

        <VmCard>
            <h2>{{ t('billing.features') }}</h2>
            <p v-if="!enabledFeatures.length" data-testid="features-empty">{{ t('billing.noFeatures') }}</p>

            <div class="vm-cluster">
                <span v-for="feature in features" :key="feature.feature" :data-testid="`feature-${feature.feature}`">
                    {{ feature.feature }}: {{ feature.enabled ? t('billing.on') : t('billing.off') }}
                </span>
            </div>
        </VmCard>

        <VmCard>
            <h2>{{ t('billing.invoices') }}</h2>
            <p v-if="!rows.length" data-testid="invoices-empty">{{ t('billing.noInvoices') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('billing.invoices')" data-testid="invoices-table" />
        </VmCard>
    </AppLayout>
</template>
