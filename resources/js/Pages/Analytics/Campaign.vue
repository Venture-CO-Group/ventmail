<script setup lang="ts">
import { VtCard, VtTable, type VtColumn } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * KAMPÁNY-RIPORT.
 *
 * A NYITÁSI ARÁNY torzít (az Apple MPP előre letölti a képeket), ezért a KATTINTÁS az,
 * amit kiemelünk — az szándékos cselekvés. A fenntartás a képernyőn van, nem a
 * dokumentációban: aki a számot nézi, annak ott kell látnia.
 */
const props = defineProps<{
    campaign: { uuid: string; name: string; status: string };
    summary: Record<string, number | string>;
    variants: Array<Record<string, number | string>>;
    daily: Array<Record<string, number | string>>;
    winner: string | null;
    links: Array<{ url: string; clicks: number; unique_clicks: number; last_clicked_at: string | null }>;
}>();

const { t } = useI18n();

type LinkRow = { url: string; clicks: string; unique: string; last: string };

const linkColumns: VtColumn<LinkRow>[] = [
    { key: 'url', label: t('analytics.link'), mono: true },
    { key: 'clicks', label: t('analytics.clicks') },
    { key: 'unique', label: t('analytics.uniqueClicks') },
    { key: 'last', label: t('analytics.lastClick') },
];

const linkRows = computed<LinkRow[]>(() =>
    props.links.map((link) => ({
        url: link.url,
        clicks: String(link.clicks),
        unique: String(link.unique_clicks),
        last: link.last_clicked_at ? new Date(link.last_clicked_at).toLocaleString() : '—',
    })),
);

const metrics = computed(() =>
    ['recipients', 'delivered', 'opened', 'clicked', 'bounced', 'unsubscribed'].filter((key) => key in props.summary),
);
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ campaign.name }}</h1>

        <div class="vm-metrics" data-testid="report-metrics">
            <VtCard v-for="key in metrics" :key="key">
                <p class="vm-metric__label">{{ t(`analytics.metric.${key}`) }}</p>
                <p class="vm-metric__value" :data-testid="`metric-${key}`">{{ summary[key] }}</p>
            </VtCard>
        </div>

        <VtCard>
            <p data-testid="analytics-mpp">{{ t('analytics.mppNote') }}</p>
        </VtCard>

        <VtCard v-if="variants.length" data-testid="report-variants">
            <h2>{{ t('analytics.variants') }}</h2>

            <div v-for="variant in variants" :key="String(variant.variant)" class="vm-cluster">
                <strong>{{ variant.variant }}</strong>
                <span>{{ t('analytics.metric.delivered') }}: {{ variant.delivered }}</span>
                <span>{{ t('analytics.metric.clicked') }}: {{ variant.clicked }}</span>
                <span v-if="winner === variant.variant" data-testid="variant-winner">{{ t('analytics.winner') }}</span>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('analytics.clickMap') }}</h2>
            <!-- A KLIKK-TÉRKÉP a retenció után is megmarad AGGREGÁLVA: a címzett-dimenzió
                 tűnik el belőle, a link-szintű szám nem. -->
            <p v-if="!linkRows.length" data-testid="links-empty">{{ t('analytics.noClicks') }}</p>
            <VtTable v-else :columns="linkColumns" :rows="linkRows" :caption="t('analytics.clickMap')" data-testid="links-table" />
        </VtCard>
    </AppLayout>
</template>
