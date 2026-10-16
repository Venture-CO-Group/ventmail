<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBadge, VtBanner, VtButton } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../Form/VmCard.vue';
import VmFormActions from '../Form/VmFormActions.vue';
import VmStat from '../Form/VmStat.vue';
import type { ImportState } from './types';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * 6. LÉPÉS — eredmény: új, frissített, kihagyott, hibás; a hibariport letöltése; és az
 * audit-bejegyzés (ki, mikor, milyen jogalappal és forrással, milyen státuszba).
 */
const props = defineProps<{ state: ImportState }>();

const { t } = useI18n({ useScope: 'global' });
const code = useCodes();
const format = useFormat();
// A számok a PROFIL számformátuma szerint (D11), nem a vue-i18n locale-ja szerint.
const n = (value: number): string => format.number(value);

const fmt = (iso: string | null): string => (iso ? format.dateTime(iso) : '—');

const counts = computed(() => props.state.counts);
const stats = computed(() => props.state.stats);
const failed = computed(() => props.state.status === 'failed');
const finishedAt = computed(() => fmt(props.state.completedAt));

const skippedHint = computed(() =>
    t('audience.import.result.skippedHint', {
        duplicate: n(stats.value.duplicate),
        suppressed: n(stats.value.suppressed),
        role: n(stats.value.role),
        disposable: n(stats.value.disposable),
        existing: n(Math.max(0, counts.value.skipped - stats.value.duplicate - stats.value.suppressed - stats.value.role - stats.value.disposable)),
    }),
);

function download(): void {
    window.location.assign(`/audience/import/${props.state.uuid}/errors`);
}
</script>

<template>
    <section data-testid="import-step-result">
        <h2 class="vm-wrap">
            {{ t('audience.import.step6.title') }}
            <VtBadge :tone="failed ? 'danger' : 'success'" data-testid="result-status">
                {{ failed ? t('audience.import.statusFailed') : t('audience.import.statusDone', { time: finishedAt }) }}
            </VtBadge>
        </h2>

        <VtBanner v-if="failed" tone="danger" role="alert" data-testid="import-failed">
            {{ t('audience.import.failed', { reason: t(`audience.import.failure.${state.failureReason ?? 'error'}`) }) }}
        </VtBanner>

        <div class="vm-import-stats">
            <VmStat :label="t('audience.import.result.new')" :value="n(counts.new)" tone="success" data-testid="result-new" />
            <VmStat :label="t('audience.import.result.updated')" :value="n(counts.updated)" data-testid="result-updated" />
            <VmStat
                :label="t('audience.import.result.skipped')"
                :value="n(counts.skipped)"
                :hint="skippedHint"
                tone="warning"
                data-testid="result-skipped"
            />
            <VmStat :label="t('audience.import.result.errors')" :value="n(counts.errors)" tone="danger" data-testid="result-errors" />
        </div>

        <VmFormActions>
            <VtButton v-if="state.hasReport" data-testid="import-download-errors" @click="download">
                {{ t('audience.import.result.downloadReport') }}
            </VtButton>
            <VtButton variant="secondary" data-testid="import-view-contacts" @click="router.visit('/audience')">
                {{ t('audience.import.result.viewContacts') }}
            </VtButton>
            <VtButton variant="ghost" data-testid="import-new" @click="router.visit('/audience/import')">
                {{ t('audience.import.result.newImport') }}
            </VtButton>
        </VmFormActions>

        <VmCard data-testid="import-audit" :title="t('audience.import.audit.title')" :heading-level="3">
            <dl class="vm-import-audit">
                <dt>{{ t('audience.import.audit.who') }}</dt>
                <dd>{{ state.startedBy ?? '—' }}</dd>
                <dt>{{ t('audience.import.audit.when') }}</dt>
                <dd class="vt-mono">{{ fmt(state.startedAt) }}</dd>
                <dt>{{ t('audience.import.audit.legalBasis') }}</dt>
                <dd>{{ state.settings.legalBasis ? t(`audience.import.legal.${state.settings.legalBasis}.title`) : '—' }}</dd>
                <dt>{{ t('audience.import.audit.source') }}</dt>
                <dd class="vm-wrap">{{ state.settings.consentSource ?? '—' }}</dd>
                <dt>{{ t('audience.import.audit.status') }}</dt>
                <dd>
                    <span :data-status="state.settings.resultingStatus ?? ''">
                        <VtBadge :tone="state.settings.resultingStatus === 'subscribed' ? 'success' : 'warning'">
                            {{ code('contactStatus', state.settings.resultingStatus) }}
                        </VtBadge>
                    </span>
                </dd>
            </dl>
        </VmCard>
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
h3,
p {
    margin: 0;
}

h2 {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
}

.vt-text-muted {
    color: var(--vt-text-secondary);
}

.vm-import-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(calc(var(--vt-space-12) * 2) * 2)), 1fr));
    gap: var(--vt-space-4);
}

.vm-import-audit {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: var(--vt-space-2) var(--vt-space-6);
    margin: 0;
}

.vm-import-audit dt {
    color: var(--vt-text-secondary);
}

.vm-import-audit dd {
    margin: 0;
    overflow-wrap: anywhere;
}
</style>
