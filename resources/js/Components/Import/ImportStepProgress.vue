<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmProgress from '../Form/VmProgress.vue';
import type { ImportState } from './types';
import { useFormat } from '../../composables/useFormat';

/**
 * 5. LÉPÉS — a futás haladása. Az oldal bezárható: a feladat a `imports` queue-n fut, az
 * állapot az adatbázisban él, a képernyő csak lekérdezi (lásd az oldal polling-ját).
 */
const props = defineProps<{ state: ImportState }>();

const { t } = useI18n({ useScope: 'global' });
const format = useFormat();
// A számok a PROFIL számformátuma szerint (D11), nem a vue-i18n locale-ja szerint.
const n = (value: number): string => format.number(value);

const counts = computed(() => props.state.counts);
const total = computed(() => props.state.profile.rows || counts.value.total);
const percent = computed(() => (total.value > 0 ? Math.min(100, Math.round((counts.value.processed / total.value) * 100)) : 0));
const queued = computed(() => props.state.status === 'queued');

// Sebesség és hátralévő idő a szerver által mért futásidőből (nem a böngésző óráján).
const rate = computed(() => (props.state.stats.elapsed > 0 ? Math.round(counts.value.processed / props.state.stats.elapsed) : 0));
const eta = computed(() => (rate.value > 0 ? Math.max(0, Math.round((total.value - counts.value.processed) / rate.value)) : null));
const totalChunks = computed(() => Math.max(1, Math.ceil(total.value / Math.max(1, props.state.chunkSize))));

const steps = computed(() => [
    { id: 'reading', label: t('audience.import.phase.reading'), state: 'done' },
    { id: 'validating', label: t('audience.import.phase.validating'), state: 'done' },
    {
        id: 'inserting',
        label: t('audience.import.phase.inserting', {
            chunk: n(Math.min(props.state.stats.chunks + 1, totalChunks.value)),
            chunks: n(totalChunks.value),
            size: n(props.state.chunkSize),
        }),
        state: queued.value ? 'todo' : 'active',
    },
    { id: 'consent', label: t('audience.import.phase.consent'), state: queued.value ? 'todo' : 'active' },
]);
</script>

<template>
    <section data-testid="import-step-progress">
        <h2 class="vm-wrap">{{ t('audience.import.step5.title') }}</h2>
        <p class="vt-text-muted">{{ t('audience.import.step5.subtitle') }}</p>

        <div class="vm-import-counter">
            <div>
                <strong class="vm-import-counter__value" data-testid="progress-processed">{{ n(counts.processed) }}</strong>
                <span class="vt-text-muted"> / {{ t('audience.import.rowsCount', { count: n(total) }) }}</span>
            </div>
            <strong data-testid="progress-percent">{{ percent }}%</strong>
        </div>

        <VmProgress :value="counts.processed" :max="total" :label="t('audience.import.step5.title')" />

        <dl class="vm-import-facts">
            <div>
                <dt>{{ t('audience.import.fact.speed') }}</dt>
                <dd>{{ t('audience.import.fact.rate', { rate: n(rate) }) }}</dd>
            </div>
            <div>
                <dt>{{ t('audience.import.fact.eta') }}</dt>
                <dd>{{ eta === null ? '—' : t('audience.import.fact.seconds', { seconds: n(eta) }) }}</dd>
            </div>
            <div>
                <dt>{{ t('audience.import.fact.job') }}</dt>
                <dd class="vt-mono">IMP-{{ state.uuid.slice(0, 8) }}</dd>
            </div>
            <div>
                <dt>{{ t('audience.import.fact.memory') }}</dt>
                <dd>{{ t('audience.import.fact.memoryValue', { mb: n(state.stats.memoryMb) }) }}</dd>
            </div>
        </dl>

        <ul class="vm-import-phases" data-testid="progress-phases">
            <li v-for="step in steps" :key="step.id" :class="`is-${step.state}`">
                <span aria-hidden="true">{{ step.state === 'done' ? '✓' : step.state === 'active' ? '●' : '○' }}</span>
                <span>{{ step.label }}</span>
            </li>
        </ul>
    </section>
</template>

<style scoped>
section {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-4);
    min-width: 0;
}

h2,
p {
    margin: 0;
}

.vt-text-muted {
    color: var(--vt-text-secondary);
}

.vm-import-counter {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--vt-space-2);
}

.vm-import-counter__value {
    font-family: var(--vt-font-display);
    font-size: var(--vt-type-display-m-size);
}

.vm-import-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(calc(var(--vt-space-12) * 2) * 2)), 1fr));
    gap: var(--vt-space-4);
    margin: 0;
}

.vm-import-facts dt {
    font-size: var(--vt-type-caption-size);
    color: var(--vt-text-secondary);
}

.vm-import-facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.vm-import-phases {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: var(--vt-space-4);
    list-style: none;
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
}

.vm-import-phases li {
    display: flex;
    gap: var(--vt-space-3);
    color: var(--vt-text-secondary);
}

.vm-import-phases li.is-done {
    color: var(--vt-status-success);
}

.vm-import-phases li.is-active {
    color: var(--vt-text-primary);
}
</style>
