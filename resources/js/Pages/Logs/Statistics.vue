<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtGlowLine, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useI18n } from 'vue-i18n';

/**
 * TAG-SZINTŰ STATISZTIKA (A3 · 26. kritérium).
 *
 * A számok a FOLYTONOS AGGREGÁTUMBÓL jönnek, nem a nyers eseményekből: a napló-retenció
 * azokat törli, tehát futásidejű számolással a 90. napon minden korábbi kampány eredménye
 * eltűnne erről a képernyőről.
 *
 * Kizárólag @venture/ui komponensekből épül; nincs beégetett design-érték.
 */

interface TagRow {
    tag: string;
    delivered: number;
    opened: number;
    clicked: number;
    bounced: number;
    open_rate: number;
    click_rate: number;
    bounce_rate: number;
}

const props = defineProps<{ days: number; channel: string | null; rows: TagRow[] }>();

const { t } = useI18n({ useScope: 'global' });

type Row = {
    tag: string;
    delivered: string;
    opened: string;
    clicked: string;
    bounced: string;
};

const columns = (): VtColumn<Row>[] => [
    { key: 'tag', label: t('logs.tag') },
    { key: 'delivered', label: t('stats.delivered') },
    { key: 'opened', label: t('stats.opened') },
    { key: 'clicked', label: t('stats.clicked') },
    { key: 'bounced', label: t('stats.bounced') },
];

const rows = (): Row[] =>
    props.rows.map((row) => ({
        tag: row.tag,
        delivered: String(row.delivered),
        opened: `${row.opened} (${row.open_rate}%)`,
        clicked: `${row.clicked} (${row.click_rate}%)`,
        bounced: `${row.bounced} (${row.bounce_rate}%)`,
    }));

const setRange = (days: number): void => {
    router.get('/logs/statistics', { days, ...(props.channel ? { channel: props.channel } : {}) }, { preserveState: true, replace: true });
};
</script>

<template>
    <AppLayout>
        <VmCard>
            <h1>{{ t('stats.title') }}</h1>
            <VtGlowLine />

            <p data-testid="stats-range">{{ t('stats.range', { days: props.days }) }}</p>

            <VtButton data-testid="stats-7" @click="setRange(7)">{{ t('stats.days', { days: 7 }) }}</VtButton>
            <VtButton data-testid="stats-30" @click="setRange(30)">{{ t('stats.days', { days: 30 }) }}</VtButton>
            <VtButton data-testid="stats-90" @click="setRange(90)">{{ t('stats.days', { days: 90 }) }}</VtButton>

            <VmTable :columns="columns()" :rows="rows()" data-testid="stats-table" />

            <p v-if="props.rows.length === 0" data-testid="stats-empty">
                {{ t('stats.empty') }}
            </p>

            <!--
                A nyitás felfelé torzít: az Apple Mail adatvédelmi funkciója (MPP) a címzett
                helyett tölti be a képeket. Ezt kimondjuk, nem a lábjegyzetbe rejtjük — a
                kampány-döntés különben hamis számra épülne.
            -->
            <p data-testid="stats-mpp">
                {{ t('stats.mpp') }}
            </p>
        </VmCard>
    </AppLayout>
</template>
