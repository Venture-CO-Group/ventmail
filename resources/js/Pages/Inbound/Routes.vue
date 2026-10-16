<script setup lang="ts">
import { VtEmptyState, VtPageHeader, type VtColumn } from '@venture-co-group/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCodes } from '../../composables/useCodes';
import { useFormat } from '../../composables/useFormat';

/**
 * BEJÖVŐ ROUTE-SZABÁLYOK ÉS LEVELEK (A4 · 30. kritérium).
 *
 * A sorrend a szabálylista lelke: prioritás szerint, azon belül a létrehozás sorrendjében.
 * A képernyő ezért ABBAN a sorrendben mutatja őket, ahogy kiértékelődnek — nem ábécé szerint,
 * nem a legutóbb szerkesztettel elöl. Aki a listát olvassa, azt látja, mi fog történni.
 *
 * Kizárólag @venture/ui komponensekből épül; nincs beégetett design-érték.
 */

interface RouteAction {
    type: string;
    target?: string | null;
}

interface InboundRoute {
    id: string;
    description: string | null;
    priority: number;
    match_type: string;
    match_field: string | null;
    expression: string | null;
    actions: RouteAction[];
    active: boolean;
    last_matched_at: string | null;
}

interface InboundMessage {
    id: string;
    sender: string | null;
    recipient: string;
    subject: string | null;
    status: string;
    received_at: string | null;
}

const props = defineProps<{ routes: InboundRoute[]; messages: InboundMessage[] }>();
const format = useFormat();
// D297: minden felirat i18n-ből (hét nyelv), az állapot a közös kódcímkéből.
const { t } = useI18n({ useScope: 'global' });
const code = useCodes();

type RouteRow = {
    priority: string;
    match: string;
    actions: string;
    active: string;
    last_matched_at: string;
};

const routeColumns = computed<VtColumn<RouteRow>[]>(() => [
    { key: 'priority', label: '#' },
    { key: 'match', label: t('inboundRoutes.match'), mono: true },
    { key: 'actions', label: t('inboundRoutes.actions') },
    { key: 'active', label: t('inboundRoutes.active') },
    { key: 'last_matched_at', label: t('inboundRoutes.lastMatched') },
]);

const describeMatch = (route: InboundRoute): string => {
    if (route.match_type === 'catch_all') return 'catch_all()';
    if (route.match_type === 'match_header') return `match_header("${route.match_field ?? ''}", "${route.expression ?? ''}")`;

    return `match_recipient("${route.expression ?? ''}")`;
};

const describeActions = (route: InboundRoute): string =>
    route.actions
        .map((action) => (action.target ? `${action.type}("${action.target}")` : `${action.type}()`))
        .join(' → ');

const routeRows = (): RouteRow[] =>
    props.routes.map((route) => ({
        priority: String(route.priority),
        match: describeMatch(route),
        actions: describeActions(route),
        active: route.active ? t('inboundRoutes.yes') : t('inboundRoutes.no'),
        last_matched_at: route.last_matched_at ? format.dateTime(route.last_matched_at) : '—',
    }));

type MessageRow = { received_at: string; sender: string; recipient: string; subject: string; status: string };

const messageColumns = computed<VtColumn<MessageRow>[]>(() => [
    { key: 'received_at', label: t('inboundRoutes.received') },
    { key: 'sender', label: t('inboundRoutes.sender'), mono: true },
    { key: 'recipient', label: t('inboundRoutes.recipient'), mono: true },
    { key: 'subject', label: t('inboundRoutes.subject') },
    { key: 'status', label: t('inboundRoutes.status') },
]);

const messageRows = (): MessageRow[] =>
    props.messages.map((message) => ({
        received_at: message.received_at ? format.dateTime(message.received_at) : '—',
        // A null-sender NEM hiányzó adat: a bounce-ok így érkeznek (RFC 5321).
        sender: message.sender ?? t('inboundRoutes.nullSender'),
        recipient: message.recipient,
        subject: message.subject ?? '—',
        status: code('status', message.status),
    }));
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('nav.settings')" :title="t('inboundRoutes.title')" :description="t('page.inbound.lead')" />

        <VmCard>
            <i18n-t keypath="inboundRoutes.order" tag="p" scope="global" data-testid="inbound-order">
                <template #stop><code>stop()</code></template>
            </i18n-t>

            <VmTable :columns="routeColumns" :rows="routeRows()" data-testid="inbound-routes" />

            <VtEmptyState v-if="props.routes.length === 0" :title="t('inboundRoutes.empty')" icon="inbox" data-testid="inbound-routes-empty" />

            <h2>{{ t('inboundRoutes.recent') }}</h2>

            <VmTable :columns="messageColumns" :rows="messageRows()" data-testid="inbound-messages" />

            <p data-testid="inbound-retention">{{ t('inboundRoutes.retention') }}</p>
        </VmCard>
    </AppLayout>
</template>
