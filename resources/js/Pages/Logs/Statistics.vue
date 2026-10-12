<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtGlowLine, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

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

type Row = {
    tag: string;
    delivered: string;
    opened: string;
    clicked: string;
    bounced: string;
};

const columns: VtColumn<Row>[] = [
    { key: 'tag', label: 'Tag' },
    { key: 'delivered', label: 'Kézbesítve' },
    { key: 'opened', label: 'Nyitás' },
    { key: 'clicked', label: 'Kattintás' },
    { key: 'bounced', label: 'Visszapattant' },
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
            <h1>Tag-statisztika</h1>
            <VtGlowLine />

            <p data-testid="stats-range">Az elmúlt {{ props.days }} nap.</p>

            <VtButton data-testid="stats-7" @click="setRange(7)">7 nap</VtButton>
            <VtButton data-testid="stats-30" @click="setRange(30)">30 nap</VtButton>
            <VtButton data-testid="stats-90" @click="setRange(90)">90 nap</VtButton>

            <VmTable :columns="columns" :rows="rows()" data-testid="stats-table" />

            <p v-if="props.rows.length === 0" data-testid="stats-empty">
                Ebben az időszakban nincs tag-hez rendelt esemény.
            </p>

            <!--
                A nyitás felfelé torzít: az Apple Mail adatvédelmi funkciója (MPP) a címzett
                helyett tölti be a képeket. Ezt kimondjuk, nem a lábjegyzetbe rejtjük — a
                kampány-döntés különben hamis számra épülne.
            -->
            <p data-testid="stats-mpp">
                A nyitási arány felfelé torzít: egyes levélkliensek (pl. Apple Mail adatvédelmi
                funkciója) a címzett helyett töltik be a képeket. A kattintás méri az emberi szándékot.
            </p>
        </VmCard>
    </AppLayout>
</template>
