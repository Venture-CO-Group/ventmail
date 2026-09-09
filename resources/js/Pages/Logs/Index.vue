<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtGlowLine, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { reactive } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Kereshető napló (A3 · 22. kritérium).
 *
 * Kizárólag @venture/ui komponensekből épül: nincs lokális gomb, kártya vagy táblázat, és
 * nincs beégetett szín/spacing/font — a design forrása a @venture/design token-készlet.
 *
 * A lapozás KURZOROS, mert a napló folyamatosan nő: offset-lapozásnál a második oldalon
 * elcsúsznának a sorok, és a support azt látná, hogy „az előbb még itt volt".
 */

interface LogRow {
    uuid: string;
    status: string;
    reason: string | null;
    channel: string;
    from: string;
    recipients: string[];
    subject: string | null;
    tags: string[];
    created_at: string | null;
}

const props = defineProps<{
    filters: Record<string, string | null>;
    messages: { items: LogRow[]; next_cursor: string | null };
}>();

const filters = reactive({
    recipient: props.filters.recipient ?? '',
    tag: props.filters.tag ?? '',
    channel: props.filters.channel ?? '',
    status: props.filters.status ?? '',
    domain: props.filters.domain ?? '',
    since: props.filters.since ?? '',
    until: props.filters.until ?? '',
});

type Row = {
    uuid: string;
    trace: string;
    recipient: string;
    subject: string;
    status: string;
    channel: string;
    tags: string;
    created_at: string;
};

const columns: VtColumn<Row>[] = [
    { key: 'created_at', label: 'Időpont' },
    { key: 'recipient', label: 'Címzett', mono: true },
    { key: 'subject', label: 'Tárgy' },
    { key: 'status', label: 'Állapot' },
    { key: 'channel', label: 'Csatorna' },
    { key: 'tags', label: 'Tagek' },
    // A trace-hez vezető gomb SAJÁT oszlop. A `VtTable` nem emit sor-kattintást (ma nincs
    // ilyen a @venture/ui-ban), a sorra akasztott kattintás pedig némán nem működne — a
    // support azt hinné, hogy a trace nem létezik. A hiányt JELENTJÜK, nem gyártunk helyi
    // táblázatot: a cella-slot + VtButton a design-rendszeren belüli megoldás.
    { key: 'trace', label: '' },
];

const rows = (): Row[] =>
    props.messages.items.map((message) => ({
        uuid: message.uuid,
        // A batch egyetlen sorban ezer címzettet tárolhat: a listában az elsőt mutatjuk,
        // a többit a darabszám jelzi — a teljes lista a trace-ben van.
        recipient:
            message.recipients.length > 1
                ? `${message.recipients[0]} +${message.recipients.length - 1}`
                : (message.recipients[0] ?? '—'),
        subject: message.subject ?? '—',
        // Az elutasítás OKA a listában is látszik: enélkül minden sorra be kellene lépni.
        status: message.reason ? `${message.status} (${message.reason})` : message.status,
        channel: message.channel,
        tags: message.tags.join(', ') || '—',
        created_at: message.created_at ? new Date(message.created_at).toLocaleString('hu-HU') : '—',
        trace: 'Trace',
    }));

const search = (): void => {
    router.get('/logs', { ...filters }, { preserveState: true, replace: true });
};

const loadMore = (): void => {
    if (!props.messages.next_cursor) return;

    // `replace: false` (alapértelmezés): a lapozás a böngésző-előzménybe kerül, tehát a
    // VISSZA gomb tényleg visszavisz az előző oldalra. Kurzoros lapozásnál nincs „előző"
    // kurzor, így az előzmény az egyetlen visszaút — enélkül a 2. oldalról nem lehetne
    // visszajutni az elsőre.
    router.get('/logs', { ...filters, cursor: props.messages.next_cursor }, { preserveState: false });
};

const openTrace = (row: Row): void => {
    router.visit(`/logs/${row.uuid}`);
};
</script>

<template>
    <AppLayout>
        <VtCard>
            <h1>Napló</h1>
            <VtGlowLine />

            <form data-testid="log-filters" @submit.prevent="search">
                <VtField label="Címzett">
                    <VtInput v-model="filters.recipient" data-testid="filter-recipient" placeholder="cimzett@example.test" />
                </VtField>
                <VtField label="Tag">
                    <VtInput v-model="filters.tag" data-testid="filter-tag" placeholder="szamla" />
                </VtField>
                <VtField label="Csatorna">
                    <VtInput v-model="filters.channel" data-testid="filter-channel" placeholder="transactional" />
                </VtField>
                <VtField label="Állapot">
                    <VtInput v-model="filters.status" data-testid="filter-status" placeholder="delivered" />
                </VtField>
                <VtField label="Domain">
                    <VtInput v-model="filters.domain" data-testid="filter-domain" placeholder="pelda.hu" />
                </VtField>
                <!-- Az időablak a backendben eddig is megvolt, az űrlapról hiányzott: a support
                     leggyakoribb kérdése épp az, hogy „mi ment ki tegnap délután". -->
                <VtField label="Ettől">
                    <VtInput v-model="filters.since" data-testid="filter-since" type="date" />
                </VtField>
                <VtField label="Eddig">
                    <VtInput v-model="filters.until" data-testid="filter-until" type="date" />
                </VtField>

                <VtButton type="submit" data-testid="log-search">Keresés</VtButton>
            </form>

            <VtTable :columns="columns" :rows="rows()" data-testid="log-table">
                <template #cell="{ row, column, value }">
                    <VtButton v-if="column.key === 'trace'" data-testid="log-trace" @click="openTrace(row)">
                        Trace
                    </VtButton>
                    <template v-else>{{ value }}</template>
                </template>
            </VtTable>

            <VtButton v-if="props.messages.next_cursor" data-testid="log-more" @click="loadMore">
                További találatok
            </VtButton>
        </VtCard>
    </AppLayout>
</template>
