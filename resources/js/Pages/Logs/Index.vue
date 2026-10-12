<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtField, VtGlowLine, VtInput, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
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

const { t, locale } = useI18n({ useScope: 'global' });

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

const columns = (): VtColumn<Row>[] => [
    { key: 'created_at', label: t('logs.time') },
    { key: 'recipient', label: t('logs.recipient'), mono: true },
    { key: 'subject', label: t('logs.subject') },
    { key: 'status', label: t('logs.status') },
    { key: 'channel', label: t('logs.channel') },
    { key: 'tags', label: t('logs.tags') },
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
        created_at: message.created_at ? new Date(message.created_at).toLocaleString(locale.value) : '—',
        trace: t('logs.trace'),
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
        <VmCard>
            <h1>{{ t('logs.title') }}</h1>
            <VtGlowLine />

            <VmForm data-testid="log-filters" @submit.prevent="search">
                <VmFormSection>
                <VmFormRow>
                <VtField :label="t('logs.recipient')">
                    <VtInput v-model="filters.recipient" data-testid="filter-recipient" placeholder="cimzett@example.test" />
                </VtField>
                <VtField :label="t('logs.tag')">
                    <VtInput v-model="filters.tag" data-testid="filter-tag" placeholder="szamla" />
                </VtField>
                <VtField :label="t('logs.channel')">
                    <VtInput v-model="filters.channel" data-testid="filter-channel" placeholder="transactional" />
                </VtField>
                <VtField :label="t('logs.status')">
                    <VtInput v-model="filters.status" data-testid="filter-status" placeholder="delivered" />
                </VtField>
                <VtField :label="t('logs.domain')">
                    <VtInput v-model="filters.domain" data-testid="filter-domain" placeholder="pelda.hu" />
                </VtField>
                <!-- Az időablak a backendben eddig is megvolt, az űrlapról hiányzott: a support
                     leggyakoribb kérdése épp az, hogy „mi ment ki tegnap délután". -->
                <VtField :label="t('logs.since')">
                    <VtInput v-model="filters.since" data-testid="filter-since" type="date" />
                </VtField>
                <VtField :label="t('logs.until')">
                    <VtInput v-model="filters.until" data-testid="filter-until" type="date" />
                </VtField>
                </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" data-testid="log-search">{{ t('logs.search') }}</VtButton>
                </VmFormActions>
            </VmForm>

            <VmTable :columns="columns()" :rows="rows()" data-testid="log-table">
                <template #cell="{ row, column, value }">
                    <VtButton v-if="column.key === 'trace'" data-testid="log-trace" @click="openTrace(row)">
                        {{ t('logs.trace') }}
                    </VtButton>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>

            <VtButton v-if="props.messages.next_cursor" data-testid="log-more" @click="loadMore">
                {{ t('logs.more') }}
            </VtButton>
        </VmCard>
    </AppLayout>
</template>
