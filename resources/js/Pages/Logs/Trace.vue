<script setup lang="ts">
import { VtGlowLine, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * ÜZENET-TRACE (A3 · 23. kritérium) — a „miért nem érkezett meg?" képernyője.
 *
 * A lánc EGYBEN: sorbaállítás → a gate minden kapujának eredménye → a kiválasztott IP →
 * az SMTP-párbeszéd a fogadó válaszkódjával → kézbesítés vagy bounce okkal → nyitás/klikk.
 * Ha bármelyik szem hiányozna, a support nem tudna válaszolni az ügyfélnek.
 *
 * Kizárólag @venture/ui komponensekből épül; nincs beégetett design-érték.
 */

interface Trace {
    message: {
        uuid: string;
        status: string;
        reason: string | null;
        channel: string;
        from: string;
        recipients: string[];
        subject: string | null;
        tags: string[];
        attempts: number;
        queued_at: string | null;
        sent_at: string | null;
        next_attempt_at: string | null;
        dkim_unsigned: string | null;
    };
    gate: { decided_at: string | null; decision: string; gates: Record<string, { passed: boolean; reason: string | null; detail: string | null }> | null };
    routing: { ip_pool_id: number | null; ip_address: string | null; domain: string | null };
    smtp: { recipient: string; remote_host: string | null; code: number | null; enhanced_code: string | null; text: string | null; lines: { direction: string; line: string }[]; started_at: string | null }[];
    attempts: { attempt: number; recipient: string; code: number | null; classification: string | null; response: string | null; at: string | null }[];
    events: { type: string; recipient: string | null; reason: string | null; occurred_at: string | null; body_sha256: string | null }[];
}

const props = defineProps<{ trace: Trace }>();

type GateRow = { gate: string; result: string; detail: string };

const gateColumns: VtColumn<GateRow>[] = [
    { key: 'gate', label: 'Kapu' },
    { key: 'result', label: 'Eredmény' },
    { key: 'detail', label: 'Részlet' },
];

const gateRows = (): GateRow[] =>
    Object.entries(props.trace.gate.gates ?? {}).map(([name, result]) => ({
        gate: name,
        result: result.passed ? 'átment' : `ELUTASÍTVA (${result.reason ?? '—'})`,
        detail: result.detail ?? '—',
    }));

type EventRow = { type: string; recipient: string; reason: string; at: string };

const eventColumns: VtColumn<EventRow>[] = [
    { key: 'at', label: 'Időpont' },
    { key: 'type', label: 'Esemény' },
    { key: 'recipient', label: 'Címzett', mono: true },
    { key: 'reason', label: 'Ok' },
];

const eventRows = (): EventRow[] =>
    props.trace.events.map((event) => ({
        type: event.type,
        recipient: event.recipient ?? '—',
        reason: event.reason ?? '—',
        at: event.occurred_at ? new Date(event.occurred_at).toLocaleString('hu-HU') : '—',
    }));

type AttemptRow = { attempt: string; recipient: string; code: string; classification: string; response: string };

const attemptColumns: VtColumn<AttemptRow>[] = [
    { key: 'attempt', label: '#' },
    { key: 'recipient', label: 'Címzett', mono: true },
    { key: 'code', label: 'Kód' },
    { key: 'classification', label: 'Osztály' },
    { key: 'response', label: 'A fogadó válasza' },
];

const attemptRows = (): AttemptRow[] =>
    props.trace.attempts.map((attempt) => ({
        attempt: String(attempt.attempt),
        recipient: attempt.recipient,
        code: attempt.code ? String(attempt.code) : '—',
        classification: attempt.classification ?? '—',
        response: attempt.response ?? '—',
    }));
</script>

<template>
    <AppLayout>
        <VmCard>
            <h1>Üzenet-trace</h1>
            <VtGlowLine />

            <dl data-testid="trace-summary">
                <dt>Azonosító</dt>
                <dd>{{ props.trace.message.uuid }}</dd>

                <dt>Állapot</dt>
                <dd data-testid="trace-status">
                    {{ props.trace.message.status }}
                    <span v-if="props.trace.message.reason"> — {{ props.trace.message.reason }}</span>
                </dd>

                <dt>Feladó</dt>
                <dd>{{ props.trace.message.from }}</dd>

                <dt>Címzettek</dt>
                <dd>{{ props.trace.message.recipients.join(', ') }}</dd>

                <dt>Küldő IP</dt>
                <dd data-testid="trace-ip">{{ props.trace.routing.ip_address ?? 'nincs (még nem ment ki)' }}</dd>

                <dt>Próbálkozások</dt>
                <dd>{{ props.trace.message.attempts }}</dd>

                <!-- Az aláíratlanul kiment levél DMARC-bukást okoz: a supportnak látnia kell. -->
                <dd v-if="props.trace.message.dkim_unsigned" data-testid="trace-dkim-warning">
                    FIGYELEM: a levél DKIM-aláírás nélkül ment ki ({{ props.trace.message.dkim_unsigned }})
                </dd>
            </dl>

            <h2>Kapuk</h2>
            <VmTable :columns="gateColumns" :rows="gateRows()" data-testid="trace-gates" />

            <h2>SMTP-párbeszéd</h2>
            <div v-for="(transaction, index) in props.trace.smtp" :key="index" data-testid="trace-smtp">
                <p>
                    {{ transaction.recipient }} — {{ transaction.remote_host ?? 'ismeretlen MX' }}:
                    <strong>{{ transaction.code ?? '—' }}</strong> {{ transaction.text ?? '' }}
                </p>
                <pre>{{ transaction.lines.map((line) => `${line.direction === 'out' ? '→' : '←'} ${line.line}`).join('\n') }}</pre>
            </div>
            <p v-if="props.trace.smtp.length === 0">Nincs rögzített SMTP-párbeszéd ehhez az üzenethez.</p>

            <h2>Kézbesítési kísérletek</h2>
            <VmTable :columns="attemptColumns" :rows="attemptRows()" data-testid="trace-attempts" />

            <h2>Események</h2>
            <VmTable :columns="eventColumns" :rows="eventRows()" data-testid="trace-events" />
        </VmCard>
    </AppLayout>
</template>
