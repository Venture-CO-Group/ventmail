<script setup lang="ts">
import { VtGlowLine, type VtColumn } from '@venture-co-group/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

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
    timeline: { kind: string; at: string | null; recipient: string | null; detail: string | null }[];
}

const props = defineProps<{ trace: Trace }>();

const { t, te } = useI18n({ useScope: 'global' });
const format = useFormat();
const code = useCodes();

type GateRow = { gate: string; result: string; detail: string };

const gateColumns = (): VtColumn<GateRow>[] => [
    { key: 'gate', label: t('trace.gate') },
    { key: 'result', label: t('trace.result') },
    { key: 'detail', label: t('trace.detail') },
];

const gateRows = (): GateRow[] =>
    Object.entries(props.trace.gate.gates ?? {}).map(([name, result]) => ({
        gate: code('gate', name),
        result: result.passed ? t('trace.passed') : t('trace.rejected', { reason: code('reason', result.reason) }),
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
        type: code('event', event.type),
        recipient: event.recipient ?? '—',
        reason: code('reason', event.reason),
        at: event.occurred_at ? format.dateTime(event.occurred_at) : '—',
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
        classification: code('bounce', attempt.classification),
        response: attempt.response ?? '—',
    }));
</script>

<template>
    <AppLayout>
        <VmCard>
            <h1>{{ t('trace.title') }}</h1>
            <VtGlowLine />

            <dl class="vm-summary" data-testid="trace-summary">
                <dt>{{ t('trace.id') }}</dt>
                <dd>{{ props.trace.message.uuid }}</dd>

                <dt>{{ t('trace.status') }}</dt>
                <dd data-testid="trace-status">
                    <!-- A gépi kód a `data-status`-ban marad (nyelvfüggetlen tesztek), a felirat fordított. -->
                    <span :data-status="props.trace.message.status">{{ code('event', props.trace.message.status) }}</span>
                    <span v-if="props.trace.message.reason" :data-reason="props.trace.message.reason"> — {{ code('reason', props.trace.message.reason) }}</span>
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
                <dd v-if="props.trace.message.dkim_unsigned" class="vm-summary__full" data-testid="trace-dkim-warning">
                    {{ t('trace.dkimWarning', { reason: code('dkim', props.trace.message.dkim_unsigned) }) }}
                </dd>
            </dl>

            <!-- AZ IDŐVONAL (v1.1 · 7. modul): a lánc egy pillantásra, időrendben. -->
            <h2>{{ t('plat.trace.timeline') }}</h2>
            <ol class="vm-timeline" data-testid="trace-timeline">
                <li v-for="(item, index) in props.trace.timeline" :key="index" class="vm-timeline__item" :data-kind="item.kind">
                    <span class="vm-timeline__dot" aria-hidden="true" />
                    <div class="vm-timeline__body">
                        <strong>{{ te(`plat.trace.kind.${item.kind}`) ? t(`plat.trace.kind.${item.kind}`) : code('event', item.kind) }}</strong>
                        <span class="vm-timeline__meta vt-mono">
                            {{ item.at ? format.dateTime(item.at) : '—' }}<template v-if="item.recipient"> · {{ item.recipient }}</template>
                        </span>
                        <code v-if="item.detail" class="vm-timeline__detail" :title="item.detail">{{ /^[A-Z_]+$/.test(item.detail) ? code('reason', item.detail) : item.detail }}</code>
                    </div>
                </li>
            </ol>

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

<style scoped>
.vm-summary {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--vt-space-2) var(--vt-space-6);
    margin: 0;
}

.vm-summary dt {
    color: var(--vt-text-secondary);
}

.vm-summary dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.vm-summary__full {
    grid-column: 1 / -1;
}

.vm-timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    border-left: thin solid var(--vt-border-strong);
    padding-left: var(--vt-space-4);
}

.vm-timeline__item {
    position: relative;
    display: flex;
    gap: var(--vt-space-3);
}

.vm-timeline__dot {
    position: absolute;
    left: calc(var(--vt-space-4) * -1 - var(--vt-space-1));
    top: var(--vt-space-1);
    width: var(--vt-space-2);
    height: var(--vt-space-2);
    border-radius: 50%;
    background: var(--vt-accent);
}

.vm-timeline__item[data-kind='bounced'] .vm-timeline__dot,
.vm-timeline__item[data-kind='gate_rejected'] .vm-timeline__dot,
.vm-timeline__item[data-kind='rejected'] .vm-timeline__dot,
.vm-timeline__item[data-kind='complained'] .vm-timeline__dot {
    background: var(--vt-status-danger);
}

.vm-timeline__item[data-kind='deferred'] .vm-timeline__dot {
    background: var(--vt-status-warning);
}

.vm-timeline__item[data-kind='delivered'] .vm-timeline__dot {
    background: var(--vt-status-success);
}

.vm-timeline__body {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-timeline__meta {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
    overflow-wrap: anywhere;
}

.vm-timeline__detail {
    font-family: var(--vt-font-mono);
    font-size: var(--vt-type-body-m-size);
    padding: var(--vt-space-2) var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background: var(--vt-bg-raised);
    overflow-wrap: anywhere;
}
</style>
