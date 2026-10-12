<script setup lang="ts">
import { VtBadge, VtButton, type VtColumn } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmFormActions from '../Form/VmFormActions.vue';
import VmStat from '../Form/VmStat.vue';
import VmTable from '../Form/VmTable.vue';
import type { ImportState } from './types';

/**
 * 4. LÉPÉS — előnézet és validáció: kategóriánként számok, és minta a problémás sorokból.
 * Az előnézet NEM ír kontaktot; ugyanaz a motor számolja, mint a valódi futás.
 */
const props = defineProps<{ state: ImportState; starting: boolean }>();

const emit = defineEmits<{ (e: 'back'): void; (e: 'start'): void }>();

const { t, n } = useI18n({ useScope: 'global' });

const computing = computed(() => props.state.status === 'previewing');
const failed = computed(() => props.state.status === 'failed');
const counts = computed(() => props.state.counts);
const stats = computed(() => props.state.stats);
const importable = computed(() => counts.value.new + counts.value.updated);

type Row = { line: string; email: string; name: string; finding: string; code: string };

const columns = computed<VtColumn<Row>[]>(() => [
    { key: 'line', label: t('audience.import.col.line'), mono: true },
    { key: 'email', label: t('audience.import.col.email'), mono: true },
    { key: 'name', label: t('audience.import.col.name') },
    { key: 'finding', label: t('audience.import.col.finding') },
]);

const rows = computed<Row[]>(() =>
    stats.value.samples.map((sample) => ({
        line: n(sample.line),
        email: sample.email || '—',
        name: sample.name || '—',
        finding: t(`audience.import.code.${sample.code}`, sample.detail),
        code: sample.code,
    })),
);

const tone = (code: string): 'danger' | 'warning' => (['invalid_email', 'missing_email', 'invalid_field', 'suppression_global', 'suppression_workspace', 'erased', 'revoked_consent', 'deleted_contact'].includes(code) ? 'danger' : 'warning');
</script>

<template>
    <section data-testid="import-step-preview">
        <h2 class="vm-wrap">{{ t('audience.import.step4.title') }}</h2>

        <p v-if="computing" class="vt-text-muted" role="status" data-testid="import-previewing">{{ t('audience.import.step4.computing') }}</p>

        <p v-else-if="failed" class="vm-banner vm-banner--danger" role="alert" data-testid="import-failed">
            {{ t('audience.import.failed', { reason: t(`audience.import.failure.${state.failureReason ?? 'error'}`) }) }}
        </p>

        <template v-else>
            <p class="vt-text-muted">{{ t('audience.import.step4.subtitle', { count: n(counts.total) }) }}</p>

            <div class="vm-import-stats">
                <VmStat
                    :label="t('audience.import.stat.importable')"
                    :value="n(importable)"
                    :hint="t('audience.import.stat.importableHint', { new: n(counts.new), updated: n(counts.updated) })"
                    tone="success"
                    data-testid="preview-importable"
                />
                <VmStat
                    :label="t('audience.import.stat.invalid')"
                    :value="n(stats.invalid)"
                    :badge="t('audience.import.stat.skipBadge')"
                    badge-tone="danger"
                    tone="danger"
                    data-testid="preview-invalid"
                />
                <VmStat
                    :label="t('audience.import.stat.duplicate')"
                    :value="n(stats.duplicate)"
                    :badge="t('audience.import.stat.firstStays')"
                    badge-tone="warning"
                    :hint="t('audience.import.stat.duplicateHint')"
                    tone="warning"
                    data-testid="preview-duplicate"
                />
                <VmStat
                    :label="t('audience.import.stat.existing')"
                    :value="n(stats.existing)"
                    :badge="t(`audience.import.update.${state.settings.updateMode}.badge`)"
                    badge-tone="accent"
                    :hint="t('audience.import.stat.existingHint')"
                    data-testid="preview-existing"
                />
                <VmStat
                    :label="t('audience.import.stat.suppressed')"
                    :value="n(stats.suppressed)"
                    :badge="t('audience.import.stat.neverRevived')"
                    badge-tone="danger"
                    :hint="t('audience.import.stat.suppressedHint')"
                    tone="danger"
                    data-testid="preview-suppressed"
                />
                <VmStat
                    :label="t('audience.import.stat.role')"
                    :value="n(stats.role)"
                    :badge="t(`audience.import.policy.${state.settings.rolePolicy}`)"
                    badge-tone="warning"
                    :hint="t('audience.import.stat.roleHint')"
                    tone="warning"
                    data-testid="preview-role"
                />
                <VmStat
                    :label="t('audience.import.stat.disposable')"
                    :value="n(stats.disposable)"
                    :badge="t(`audience.import.policy.${state.settings.disposablePolicy}`)"
                    badge-tone="warning"
                    :hint="t('audience.import.stat.disposableHint')"
                    tone="warning"
                    data-testid="preview-disposable"
                />
            </div>

            <template v-if="rows.length">
                <h3>{{ t('audience.import.step4.sampleHeading') }}</h3>
                <VmTable :columns="columns" :rows="rows" :caption="t('audience.import.step4.sampleHeading')" data-testid="preview-samples">
                    <template #cell="{ column, row, value }">
                        <VtBadge v-if="column.key === 'finding'" :tone="tone(row.code)" size="sm">{{ value }}</VtBadge>
                        <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                    </template>
                </VmTable>
            </template>
        </template>

        <VmFormActions>
            <VtButton variant="glass" data-testid="import-back" @click="emit('back')">{{ t('audience.import.back') }}</VtButton>
            <VtButton
                :disabled="computing || failed || starting || importable === 0"
                data-testid="import-start"
                @click="emit('start')"
            >
                {{ t('audience.import.start') }}
            </VtButton>
        </VmFormActions>
        <p v-if="!computing && !failed && importable === 0" class="vm-banner vm-banner--warning" role="status">
            {{ t('audience.import.step4.nothing') }}
        </p>
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

.vt-text-muted {
    color: var(--vt-text-muted);
}

.vm-import-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(var(--vt-space-24) * 2)), 1fr));
    gap: var(--vt-space-4);
}
</style>
