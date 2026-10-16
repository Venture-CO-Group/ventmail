<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBadge, VtButton, type VtColumn } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * KONTAKT ADATLAPJA: mezők, listák, címkék, a marketing-hozzájárulás állapota és a
 * hozzájárulás TÖRTÉNETE — az import forrásával és jogalapjával.
 */
interface HistoryEvent {
    uuid: string;
    type: string;
    legalBasis: string;
    source: string | null;
    detail: string | null;
    importFile: string | null;
    resultingStatus: string | null;
    occurredAt: string | null;
}

const props = defineProps<{
    contact: {
        uuid: string;
        email: string;
        firstName: string | null;
        lastName: string | null;
        status: string;
        validation: string | null;
        subscribedAt: string | null;
        createdAt: string | null;
        attributes: Array<{ key: string; label: string; value: string }>;
    };
    lists: Array<{ uuid: string; name: string; status: string }>;
    tags: string[];
    consent: { status: string; legalBasis: string; source: string | null; confirmedAt: string | null; revokedAt: string | null } | null;
    history: HistoryEvent[];
}>();

const { t } = useI18n({ useScope: 'global' });
const code = useCodes();
const format = useFormat();

const fmt = (iso: string | null): string => (iso ? format.dateTime(iso) : '—');

const fullName = computed(() => [props.contact.firstName, props.contact.lastName].filter(Boolean).join(' ') || '—');

type Row = { when: string; type: string; basis: string; source: string };

const columns = computed<VtColumn<Row>[]>(() => [
    { key: 'when', label: t('audience.contact.history.when'), mono: true },
    { key: 'type', label: t('audience.contact.history.type') },
    { key: 'basis', label: t('audience.contact.history.basis') },
    { key: 'source', label: t('audience.contact.history.source') },
]);

const rows = computed<Row[]>(() =>
    props.history.map((event) => ({
        when: fmt(event.occurredAt),
        type: t(`audience.contact.event.${event.type}`, event.type),
        basis: event.legalBasis,
        source: [event.detail ?? event.source, event.importFile ? `${t('audience.contact.history.file')}: ${event.importFile}` : null].filter(Boolean).join(' · ') || '—',
    })),
);
</script>

<template>
    <AppLayout>
        <header class="vm-contact-head">
            <div>
                <h1 class="vt-num vm-wrap" data-testid="contact-email">{{ contact.email }}</h1>
                <p class="vm-contact-name">{{ fullName }}</p>
            </div>
            <VtButton variant="ghost" @click="router.visit('/audience')">{{ t('audience.contact.back') }}</VtButton>
        </header>

        <VmCard :title="t('audience.contact.fields')">
            <dl class="vm-contact-facts" data-testid="contact-fields">
                <dt>{{ t('audience.status') }}</dt>
                <dd><VtBadge :tone="contact.status === 'subscribed' ? 'success' : 'warning'" data-testid="contact-status" :data-status="contact.status">{{ code('contactStatus', contact.status) }}</VtBadge></dd>
                <dt>{{ t('audience.validation') }}</dt>
                <dd>{{ contact.validation ?? '—' }}</dd>
                <dt>{{ t('audience.contact.subscribedAt') }}</dt>
                <dd class="vt-mono">{{ fmt(contact.subscribedAt) }}</dd>
                <dt>{{ t('audience.contact.createdAt') }}</dt>
                <dd class="vt-mono">{{ fmt(contact.createdAt) }}</dd>
                <template v-for="attribute in contact.attributes" :key="attribute.key">
                    <dt>{{ attribute.label }}</dt>
                    <dd class="vm-wrap">{{ attribute.value }}</dd>
                </template>
            </dl>
        </VmCard>

        <VmCard :title="t('audience.contact.listsAndTags')">
            <p v-if="!lists.length && !tags.length" class="vm-contact-muted">{{ t('audience.contact.none') }}</p>
            <div class="vm-contact-chips" data-testid="contact-lists">
                <VtBadge v-for="list in lists" :key="list.uuid" tone="accent">{{ list.name }} · {{ code('contactStatus', list.status) }}</VtBadge>
                <VtBadge v-for="tag in tags" :key="tag" tone="neutral">{{ tag }}</VtBadge>
            </div>
        </VmCard>

        <VmCard :title="t('audience.contact.consent')">
            <p v-if="!consent" class="vm-contact-muted">{{ t('audience.contact.noConsent') }}</p>
            <dl v-else class="vm-contact-facts" data-testid="contact-consent">
                <dt>{{ t('audience.status') }}</dt>
                <dd><VtBadge :tone="consent.status === 'confirmed' ? 'success' : 'warning'" :data-consent="consent.status">{{ code('consent', consent.status) }}</VtBadge></dd>
                <dt>{{ t('audience.contact.history.basis') }}</dt>
                <dd>{{ consent.legalBasis }}</dd>
                <dt>{{ t('audience.contact.history.source') }}</dt>
                <dd>{{ consent.source ?? '—' }}</dd>
            </dl>

            <h3>{{ t('audience.contact.history.title') }}</h3>
            <p v-if="!rows.length" class="vm-contact-muted">{{ t('audience.contact.history.empty') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('audience.contact.history.title')" data-testid="contact-history" />
        </VmCard>
    </AppLayout>
</template>

<style scoped>
.vm-contact-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-contact-head h1,
.vm-contact-head p,
h2,
h3 {
    margin: 0;
}

.vm-contact-name,
.vm-contact-muted {
    color: var(--vt-text-secondary);
}

.vm-contact-facts {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: var(--vt-space-2) var(--vt-space-6);
    margin: 0;
}

.vm-contact-facts dt {
    color: var(--vt-text-secondary);
}

.vm-contact-facts dd {
    margin: 0;
    min-width: 0;
}

.vm-contact-chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-2);
}
</style>
