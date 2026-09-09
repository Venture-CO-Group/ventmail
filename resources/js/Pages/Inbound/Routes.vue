<script setup lang="ts">
import { VtCard, VtGlowLine, VtTable, type VtColumn } from '@venture/ui';
import AppLayout from '../../Layouts/AppLayout.vue';

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

type RouteRow = {
    priority: string;
    match: string;
    actions: string;
    active: string;
    last_matched_at: string;
};

const routeColumns: VtColumn<RouteRow>[] = [
    { key: 'priority', label: '#' },
    { key: 'match', label: 'Illesztés', mono: true },
    { key: 'actions', label: 'Akciók' },
    { key: 'active', label: 'Aktív' },
    { key: 'last_matched_at', label: 'Utolsó találat' },
];

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
        active: route.active ? 'igen' : 'nem',
        last_matched_at: route.last_matched_at ? new Date(route.last_matched_at).toLocaleString('hu-HU') : '—',
    }));

type MessageRow = { received_at: string; sender: string; recipient: string; subject: string; status: string };

const messageColumns: VtColumn<MessageRow>[] = [
    { key: 'received_at', label: 'Érkezett' },
    { key: 'sender', label: 'Feladó', mono: true },
    { key: 'recipient', label: 'Címzett', mono: true },
    { key: 'subject', label: 'Tárgy' },
    { key: 'status', label: 'Állapot' },
];

const messageRows = (): MessageRow[] =>
    props.messages.map((message) => ({
        received_at: message.received_at ? new Date(message.received_at).toLocaleString('hu-HU') : '—',
        // A null-sender NEM hiányzó adat: a bounce-ok így érkeznek (RFC 5321).
        sender: message.sender ?? '(bounce)',
        recipient: message.recipient,
        subject: message.subject ?? '—',
        status: message.status,
    }));
</script>

<template>
    <AppLayout>
        <VtCard>
            <h1>Bejövő szabályok</h1>
            <VtGlowLine />

            <p data-testid="inbound-order">
                A szabályok ebben a sorrendben értékelődnek ki. Az első <code>stop()</code> lezárja a láncot.
            </p>

            <VtTable :columns="routeColumns" :rows="routeRows()" data-testid="inbound-routes" />

            <p v-if="props.routes.length === 0" data-testid="inbound-routes-empty">
                Még nincs szabály. Szabály nélkül a beérkező levél eltárolódik, és a retenció lejártakor törlődik.
            </p>

            <h2>Utolsó beérkezett levelek</h2>

            <VtTable :columns="messageColumns" :rows="messageRows()" data-testid="inbound-messages" />

            <p data-testid="inbound-retention">
                A beérkezett levél idegen tartalom: rövid retencióval tároljuk, majd a nyers levél és a
                csatolmányok is törlődnek.
            </p>
        </VtCard>
    </AppLayout>
</template>
