<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * JOURNEY-LÁNC.
 *
 * A lánc LISTA, nem vászon: a `@venture/ui`-ban nincs gráf-primitív, és a golden rule a
 * lokális komponens-gyártást tiltja. A lépéssor viszont sorrend — listaként pontosan
 * leírható és billentyűzettel is bejárható.
 *
 * JELENTÉS a @venture/ui felé: hiányzik (10) gráf/folyamat-vászon és (11) elágazás-jelölő.
 */
const props = defineProps<{
    journey: {
        uuid: string;
        name: string;
        description: string | null;
        status: string;
        trigger: string;
        allowReentry: boolean;
        firstStep: string | null;
    };
    steps: Array<{ uuid: string; type: string; position: number; config: Record<string, unknown>; next: string | null; else: string | null }>;
    stepTypes: string[];
    runs: { active: number; completed: number };
}>();

const { t } = useI18n();

type Row = { position: string; type: string; next: string; branch: string };

const columns: VtColumn<Row>[] = [
    { key: 'position', label: '#' },
    { key: 'type', label: t('journeys.stepType') },
    { key: 'next', label: t('journeys.next') },
    { key: 'branch', label: t('journeys.elseBranch') },
];

const rows = computed<Row[]>(() =>
    props.steps.map((step) => ({
        position: String(step.position),
        type: step.type,
        next: step.next ?? t('journeys.end'),
        // AZ ELÁGAZÁS `else`-ága: enélkül a feltétel „igaz" ága látszana csak, és a másik
        // fele láthatatlan maradna.
        branch: step.else ?? '—',
    })),
);

function activate(): void {
    router.post(`/journeys/${props.journey.uuid}/activate`, {}, { preserveScroll: true });
}

function pause(): void {
    router.post(`/journeys/${props.journey.uuid}/pause`, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ journey.name }}</h1>

        <p class="vm-banner" role="status" data-testid="journey-status">
            {{ t('journeys.statusIs', { status: journey.status, trigger: journey.trigger }) }}
        </p>

        <VmCard>
            <div class="vm-cluster">
                <span data-testid="runs-active">{{ t('journeys.activeRuns') }}: {{ runs.active }}</span>
                <span data-testid="runs-completed">{{ t('journeys.completedRuns') }}: {{ runs.completed }}</span>
            </div>

            <div class="vm-cluster">
                <VtButton data-testid="journey-activate" @click="activate">{{ t('journeys.activate') }}</VtButton>
                <VtButton variant="ghost" data-testid="journey-pause" @click="pause">{{ t('journeys.pause') }}</VtButton>
            </div>

            <p>{{ t('journeys.activationNote') }}</p>
        </VmCard>

        <VmCard>
            <h2>{{ t('journeys.chain') }}</h2>

            <p v-if="!rows.length" data-testid="steps-empty">{{ t('journeys.noSteps') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('journeys.chain')" data-testid="steps-table" />
        </VmCard>
    </AppLayout>
</template>
