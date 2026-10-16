<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtBanner, VtButton, VtCheckbox, VtField, VtInput, VtSelect, type VtColumn } from '@venture-co-group/ui';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmStateBadge from '../../Components/Platform/VmStateBadge.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * ESEMÉNYNAPLÓ (A3 · 22. kritérium, v1.1 · 7. modul).
 *
 * Kereshető és szűrhető: címzett, Message-ID, állapot, domain, tag, csatorna, időablak. A
 * szűrés a SZERVEREN történik; az export ugyanazt a szűrt halmazt adja CSV-ben (streamelve,
 * korlátosan, alapból maszkolt címzettel).
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
    domains: string[];
    statuses: string[];
    retentionDays: number;
    exportMaxRows: number;
}>();

const { t, te } = useI18n({ useScope: 'global' });
const code = useCodes();
const format = useFormat();

const dateOnly = (value: string | null | undefined): string => (value ? value.slice(0, 10) : '');

const filters = reactive({
    recipient: props.filters.recipient ?? '',
    message_id: props.filters.message_id ?? '',
    tag: props.filters.tag ?? '',
    channel: props.filters.channel ?? '',
    status: props.filters.status ?? '',
    domain: props.filters.domain ?? '',
    since: dateOnly(props.filters.since),
    until: dateOnly(props.filters.until),
});

const pii = ref(false);

const statusOptions = computed(() => [{ value: '', label: t('plat.logs.anyStatus') }, ...props.statuses.map((status) => ({ value: status, label: te(`plat.state.${status}`) ? t(`plat.state.${status}`) : status }))]);
const domainOptions = computed(() => [{ value: '', label: t('plat.logs.anyDomain') }, ...props.domains.map((domain) => ({ value: domain, label: domain }))]);
const channelOptions = computed(() => [
    { value: '', label: t('plat.logs.anyChannel') },
    ...['transactional', 'marketing', 'campaign', 'journey'].map((channel) => ({ value: channel, label: channel })),
]);

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

const columns = computed<VtColumn<Row>[]>(() => [
    { key: 'created_at', label: t('logs.time'), mono: true },
    { key: 'recipient', label: t('logs.recipient'), mono: true },
    { key: 'subject', label: t('logs.subject') },
    { key: 'status', label: t('logs.status') },
    { key: 'tags', label: t('logs.tags') },
    { key: 'uuid', label: 'Message-ID', mono: true },
    // A trace-hez vezető gomb SAJÁT oszlop (a `VtTable` nem emit sor-kattintást).
    { key: 'trace', label: '' },
]);

const reasons = computed(() => new Map(props.messages.items.map((message) => [message.uuid, message.reason])));

const rows = computed<Row[]>(() =>
    props.messages.items.map((message) => ({
        uuid: message.uuid,
        // A batch egyetlen sorban ezer címzettet tárolhat: a listában az elsőt mutatjuk,
        // a többit a darabszám jelzi — a teljes lista a trace-ben van.
        recipient:
            message.recipients.length > 1
                ? `${message.recipients[0]} +${message.recipients.length - 1}`
                : (message.recipients[0] ?? '—'),
        subject: message.subject ?? '—',
        status: message.status,
        channel: code('channel', message.channel),
        tags: message.tags.join(', ') || '—',
        created_at: message.created_at ? format.dateTime(message.created_at) : '—',
        trace: t('logs.trace'),
    })),
);

function query(): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')) as Record<string, string>;
}

const page = usePage<{ abilities: string[] }>();
const canExportPii = computed(() => (page.props.abilities ?? []).includes('logs.export_pii'));
const exporting = ref(false);
const exportError = ref<string | null>(null);

/*
 | AZ EXPORT POST + CSRF (N2): auditált, fékezett mellékhatás, ezért nem GET-link. A
 | `XSRF-TOKEN` sütit fejlécbe tesszük (ugyanúgy, mint az Inertia/axios), a választ blobként
 | töltjük le — az oldal marad, ahol volt.
 */
async function downloadExport(): Promise<void> {
    exporting.value = true;
    exportError.value = null;

    try {
        const raw = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
        const token = raw ? decodeURIComponent(raw.slice('XSRF-TOKEN='.length)) : '';

        const response = await fetch('/logs/export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'text/csv, application/json', 'X-XSRF-TOKEN': token },
            body: JSON.stringify({ ...query(), pii: pii.value && canExportPii.value }),
        });

        if (!response.ok) {
            exportError.value = response.status === 429 ? t('plat.logs.exportThrottled') : t('plat.logs.exportFailed');

            return;
        }

        const disposition = response.headers.get('Content-Disposition') ?? '';
        const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'ventmail-log.csv';
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } finally {
        exporting.value = false;
    }
}

function search(): void {
    router.get('/logs', query(), { preserveState: true, replace: true });
}

function preset(days: number): void {
    const since = new Date(Date.now() - days * 86_400_000);
    filters.since = since.toISOString().slice(0, 10);
    filters.until = '';
    search();
}

function loadMore(): void {
    if (!props.messages.next_cursor) return;

    // A lapozás a böngésző-előzménybe kerül: kurzoros lapozásnál ez az egyetlen visszaút.
    router.get('/logs', { ...query(), cursor: props.messages.next_cursor }, { preserveState: false });
}

function openTrace(row: Row): void {
    router.visit(`/logs/${row.uuid}`);
}
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('plat.eyebrow.logs')" :title="t('logs.title')" :lead="t('plat.logs.lead')">
            <div class="vm-export">
                <VtCheckbox v-if="canExportPii" v-model="pii" data-testid="export-pii">{{ t('plat.logs.includePii') }}</VtCheckbox>
                <VtButton variant="secondary" data-testid="log-export" :disabled="exporting" @click="downloadExport">{{ t('plat.logs.export') }}</VtButton>
            </div>
        </VmPageHeader>

        <VtBanner v-if="exportError" tone="danger" role="alert" data-testid="export-error">{{ exportError }}</VtBanner>

        <VmCard>
            <VmForm data-testid="log-filters" @submit.prevent="search">
                <VmFormSection>
                    <VmFormRow>
                        <VtField :label="t('logs.recipient')">
                            <VtInput v-model="filters.recipient" data-testid="filter-recipient" placeholder="cimzett@example.test" />
                        </VtField>
                        <VtField label="Message-ID" :hint="t('plat.logs.messageIdHint')">
                            <VtInput v-model="filters.message_id" data-testid="filter-message-id" placeholder="&lt;uuid@…&gt;" />
                        </VtField>
                        <VtField :label="t('logs.status')">
                            <VtSelect v-model="filters.status" :options="statusOptions" data-testid="filter-status" />
                        </VtField>
                        <VtField :label="t('logs.domain')">
                            <VtSelect v-model="filters.domain" :options="domainOptions" data-testid="filter-domain" />
                        </VtField>
                    </VmFormRow>
                    <VmFormRow>
                        <VtField :label="t('logs.tag')">
                            <VtInput v-model="filters.tag" data-testid="filter-tag" placeholder="szamla" />
                        </VtField>
                        <VtField :label="t('logs.channel')">
                            <VtSelect v-model="filters.channel" :options="channelOptions" data-testid="filter-channel" />
                        </VtField>
                        <VtField :label="t('logs.since')">
                            <VtInput v-model="filters.since" data-testid="filter-since" type="date" />
                        </VtField>
                        <VtField :label="t('logs.until')">
                            <VtInput v-model="filters.until" data-testid="filter-until" type="date" />
                        </VtField>
                    </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton variant="ghost" size="sm" data-testid="preset-1" @click="preset(1)">{{ t('plat.logs.last24h') }}</VtButton>
                    <VtButton variant="ghost" size="sm" data-testid="preset-7" @click="preset(7)">{{ t('plat.logs.lastDays', { days: 7 }) }}</VtButton>
                    <VtButton variant="ghost" size="sm" data-testid="preset-30" @click="preset(30)">{{ t('plat.logs.lastDays', { days: 30 }) }}</VtButton>
                    <VtButton type="submit" data-testid="log-search">{{ t('logs.search') }}</VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <VmTable :columns="columns" :rows="rows" :caption="t('logs.title')" data-testid="log-table">
                <template #cell="{ row, column, value }">
                    <VtButton v-if="column.key === 'trace'" variant="ghost" size="sm" data-testid="log-trace" @click="openTrace(row)">
                        {{ t('logs.trace') }}
                    </VtButton>
                    <span v-else-if="column.key === 'status'" class="vm-status-cell" :title="reasons.get(row.uuid) ?? ''">
                        <VmStateBadge :state="String(value)" />
                    </span>
                    <span v-else-if="column.key === 'uuid'" class="vt-mono" :title="String(value)" :data-message-id="value">{{ String(value).slice(0, 8) }}…</span>
                    <span v-else-if="column.key === 'subject' || column.key === 'tags'" class="vm-truncate vm-subject" :title="String(value ?? '')">{{ value }}</span>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>

            <div class="vm-foot">
                <span class="vm-muted" data-testid="log-retention">
                    {{ t('plat.logs.footer', { count: messages.items.length, days: retentionDays }) }}
                </span>
                <VtButton v-if="props.messages.next_cursor" variant="secondary" data-testid="log-more" @click="loadMore">
                    {{ t('logs.more') }}
                </VtButton>
            </div>
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-export {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vm-status-cell {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-subject {
    display: inline-block;
    max-width: calc(calc(var(--vt-space-8) * 4) * 2);
}

.vm-muted {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}

.vm-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
}
</style>
