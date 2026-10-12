<script setup lang="ts">
import { VtGlowLine, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useI18n } from 'vue-i18n';

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

const { t, locale } = useI18n({ useScope: 'global' });

type GateRow = { gate: string; result: string; detail: string };

const gateColumns = (): VtColumn<GateRow>[] => [
    { key: 'gate', label: t('trace.gate') },
    { key: 'result', label: t('trace.result') },
    { key: 'detail', label: t('trace.detail') },
];

const gateRows = (): GateRow[] =>
    Object.entries(props.trace.gate.gates ?? {}).map(([name, result]) => ({
        gate: name,
        result: result.passed ? t('trace.passed') : t('trace.rejected', { reason: result.reason ?? '—' }),
        detail: result.detail ?? '—',
    }));

type EventRow = { type: string; recipient: string; reason: string; at: string };

const eventColumns = (): VtColumn<EventRow>[] => [
    { key: 'at', label: t('logs.time') },
    { key: 'type', label: t('trace.event') },
    { key: 'recipient', label: t('logs.recipient'), mono: true },
    { key: 'reason', label: t('trace.reason') },
];

const eventRows = (): EventRow[] =>
    props.trace.events.map((event) => ({
        type: event.type,
        recipient: event.recipient ?? '—',
        reason: event.reason ?? '—',
        at: event.occurred_at ? new Date(event.occurred_at).toLocaleString(locale.value) : '—',
    }));

type AttemptRow = { attempt: string; recipient: string; code: string; classification: string; response: string };

const attemptColumns = (): VtColumn<AttemptRow>[] => [
    { key: 'attempt', label: '#' },
    { key: 'recipient', label: t('logs.recipient'), mono: true },
    { key: 'code', label: t('trace.code') },
    { key: 'classification', label: t('trace.class') },
    { key: 'response', label: t('trace.response') },
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
            <h1>{{ t('trace.title') }}</h1>
            <VtGlowLine />

            <dl data-testid="trace-summary">
                <dt>{{ t('trace.id') }}</dt>
                <dd>{{ props.trace.message.uuid }}</dd>

                <dt>{{ t('trace.status') }}</dt>
                <dd data-testid="trace-status">
                    {{ props.trace.message.status }}
                    <span v-if="props.trace.message.reason"> — {{ props.trace.message.reason }}</span>
                </dd>

                <dt>{{ t('trace.from') }}</dt>
                <dd>{{ props.trace.message.from }}</dd>

                <dt>{{ t('trace.recipients') }}</dt>
                <dd>{{ props.trace.message.recipients.join(', ') }}</dd>

                <dt>{{ t('trace.ip') }}</dt>
                <dd data-testid="trace-ip">{{ props.trace.routing.ip_address ?? t('trace.noIp') }}</dd>

                <dt>{{ t('trace.attempts') }}</dt>
                <dd>{{ props.trace.message.attempts }}</dd>

                <!-- Az aláíratlanul kiment levél DMARC-bukást okoz: a supportnak látnia kell. -->
                <dd v-if="props.trace.message.dkim_unsigned" data-testid="trace-dkim-warning">
                    {{ t('trace.dkimWarning', { reason: props.trace.message.dkim_unsigned }) }}
                </dd>
            </dl>

            <h2>{{ t('trace.gates') }}</h2>
            <VmTable :columns="gateColumns()" :rows="gateRows()" data-testid="trace-gates" />

            <h2>{{ t('trace.smtp') }}</h2>
            <div v-for="(transaction, index) in props.trace.smtp" :key="index" data-testid="trace-smtp">
                <p>
                    {{ transaction.recipient }} — {{ transaction.remote_host ?? t('trace.unknownMx') }}:
                    <strong>{{ transaction.code ?? '—' }}</strong> {{ transaction.text ?? '' }}
                </p>
                <pre>{{ transaction.lines.map((line) => `${line.direction === 'out' ? '→' : '←'} ${line.line}`).join('\n') }}</pre>
            </div>
            <p v-if="props.trace.smtp.length === 0">{{ t('trace.noSmtp') }}</p>

            <h2>{{ t('trace.attemptsTitle') }}</h2>
            <VmTable :columns="attemptColumns()" :rows="attemptRows()" data-testid="trace-attempts" />

            <h2>{{ t('trace.events') }}</h2>
            <VmTable :columns="eventColumns()" :rows="eventRows()" data-testid="trace-events" />
        </VmCard>
    </AppLayout>
</template>
