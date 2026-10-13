<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtStepper, type VtStep } from '@venture/ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ImportStepColumns from '../../Components/Import/ImportStepColumns.vue';
import ImportStepPreview from '../../Components/Import/ImportStepPreview.vue';
import ImportStepProgress from '../../Components/Import/ImportStepProgress.vue';
import ImportStepResult from '../../Components/Import/ImportStepResult.vue';
import ImportStepSettings from '../../Components/Import/ImportStepSettings.vue';
import ImportStepUpload from '../../Components/Import/ImportStepUpload.vue';
import type { ImportErrors, ImportField, ImportList, ImportState } from '../../Components/Import/types';
import VmCard from '../../Components/Form/VmCard.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * KONTAKTOK IMPORTÁLÁSA — hat lépéses varázsló (kézi / beillesztett lista / CSV / XLSX).
 *
 * Az ÁLLAPOT a szerveren él (`contact_imports`): a lépések POST + redirect, a háttérfeladat
 * haladását ez az oldal kérdezi le (polling). Az aktuális lépést a kötegének állapotából
 * vezetjük le, ezért újratöltés után ugyanott folytatódik.
 */
const props = defineProps<{
    import: ImportState | null;
    lists: ImportList[];
    fields: ImportField[];
    builtinFields: string[];
    limits: { maxMegabytes: number; maxRows: number };
}>();

const { t } = useI18n({ useScope: 'global' });

const STEP_IDS = ['upload', 'columns', 'settings', 'preview', 'progress', 'result'] as const;

function stepFor(state: ImportState | null): number {
    switch (state?.status) {
        case undefined:
            return 0;
        case 'inspecting':
            return 0;
        case 'draft':
            return 1;
        case 'previewing':
        case 'previewed':
            return 3;
        case 'queued':
        case 'processing':
            return 4;
        default:
            return 5;
    }
}

const run = computed(() => props.import);
const step = ref(stepFor(props.import));
const errors = ref<ImportErrors>({});
const starting = ref(false);

const steps = computed<VtStep[]>(() =>
    STEP_IDS.map((id, index) => ({ id, label: t(`audience.import.steps.${id}`), description: String(index + 1).padStart(2, '0') })),
);

// A szerver-állapot ELŐRE viszi a lépést (futás indult / véget ért), de sosem viszi vissza:
// a felhasználó szabadon lapozhat a korábbi lépésekre.
watch(
    () => props.import?.status,
    (status, previous) => {
        // A háttér-felmérés (D71) véget ért: a varázsló folytatódik az oszlopokkal.
        if (previous === 'inspecting' && status === 'draft') step.value = 1;
        else if (status === 'queued' || status === 'processing') step.value = 4;
        else if (status === 'completed' || status === 'failed') step.value = 5;
        else if (status === undefined) step.value = 0;
    },
);

const now = ref(Date.now());
const inspectingStuck = computed(
    () => props.import?.status === 'inspecting' && props.import.updatedAt !== null && now.value - new Date(props.import.updatedAt).getTime() > 5 * 60_000,
);

function cancelStuck(): void {
    if (props.import) router.post(`/audience/import/${props.import.uuid}/cancel`);
}

const polling = computed(() => ['inspecting', 'previewing', 'queued', 'processing'].includes(props.import?.status ?? ''));
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();

        if (polling.value) {
            router.reload({ only: ['import'] });
        }
    }, 1000);
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});

function start(): void {
    if (!props.import) return;

    starting.value = true;

    router.post(`/audience/import/${props.import.uuid}/start`, {}, {
        preserveScroll: true,
        onSuccess: () => (step.value = 4),
        onError: (failed) => (errors.value = failed),
        onFinish: () => (starting.value = false),
    });
}

function leave(): void {
    // Csak a még nem futtatott (draft / previewed) köteg törölhető; a többi esetben egyszerűen kilépünk.
    if (props.import && /^(draft|previewed)$/.test(props.import.status)) {
        router.post(`/audience/import/${props.import.uuid}/cancel`);

        return;
    }

    router.visit('/audience');
}
</script>

<template>
    <AppLayout>
        <header class="vm-import-head">
            <div>
                <p class="vt-text-mono vm-import-head__eyebrow">{{ t('audience.import.eyebrow') }}</p>
                <h1 class="vt-text-display">{{ t('audience.import.title') }}</h1>
                <p class="vm-import-head__subtitle">{{ t('audience.import.subtitle') }}</p>
            </div>
            <VtButton variant="ghost" data-testid="import-exit" @click="leave">{{ t('audience.import.exit') }}</VtButton>
        </header>

        <div data-testid="import-wizard" class="vm-import-wizard">
            <!-- D284: keskeny képernyőn a hat lépés VÍZSZINTESEN görgethető (a mockup szerint), nem
                 nyomódik betűnkénti oszlopokká. -->
            <div class="vm-import-steps">
                <VtStepper
                    :steps="steps"
                    :current="step"
                    :label="t('audience.import.title')"
                    :status-labels="{ complete: t('audience.import.stepDone'), current: t('audience.import.stepCurrent'), upcoming: '' }"
                    data-testid="import-steps"
                />
            </div>

            <p v-if="errors.import" class="vm-banner vm-banner--warning" role="alert" data-testid="import-error">{{ errors.import }}</p>

            <VmCard v-if="run?.status === 'inspecting'" data-testid="import-inspecting">
                <h2>{{ t('audience.import.inspecting.title') }}</h2>
                <p class="vm-import-counter" role="status">{{ t('audience.import.inspecting.hint') }}</p>
                <!-- ELAKADT felmérés (5 perc után): üzenet és Mégse — a szerver is csak ekkor engedi. -->
                <template v-if="inspectingStuck">
                    <p class="vm-banner vm-banner--warning" role="alert" data-testid="import-inspecting-stuck">{{ t('audience.import.inspecting.stuck') }}</p>
                    <VtButton variant="glass" data-testid="import-inspecting-cancel" @click="cancelStuck">{{ t('audience.import.inspecting.cancel') }}</VtButton>
                </template>
            </VmCard>

            <VmCard v-else>
                <ImportStepUpload
                    v-if="step === 0"
                    :state="run"
                    :errors="errors"
                    :max-megabytes="limits.maxMegabytes"
                    :max-rows="limits.maxRows"
                    @created="step = 1"
                    @next="step = 1"
                    @errors="errors = $event"
                />

                <ImportStepColumns
                    v-else-if="step === 1 && run"
                    :state="run"
                    :fields="fields"
                    :builtin-fields="builtinFields"
                    :errors="errors"
                    @back="step = 0"
                    @saved="step = 2"
                    @errors="errors = $event"
                />

                <ImportStepSettings
                    v-else-if="step === 2 && run"
                    :state="run"
                    :lists="lists"
                    :errors="errors"
                    @back="step = 1"
                    @saved="step = 3"
                    @errors="errors = $event"
                />

                <ImportStepPreview
                    v-else-if="step === 3 && run"
                    :state="run"
                    :starting="starting"
                    @back="step = 2"
                    @start="start"
                />

                <ImportStepProgress v-else-if="step === 4 && run" :state="run" />

                <ImportStepResult v-else-if="step === 5 && run" :state="run" />
            </VmCard>

            <p class="vm-import-counter" data-testid="import-step-counter">{{ t('audience.import.stepCounter', { current: step + 1, total: STEP_IDS.length }) }}</p>
        </div>
    </AppLayout>
</template>

<style scoped>
.vm-import-steps {
    max-inline-size: 100%;
    overflow-x: auto;
    min-width: 0;
}

.vm-import-steps > * {
    min-inline-size: calc(var(--vt-space-24) * 9.5);
}

.vm-import-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--vt-space-4);
}

.vm-import-head > div {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-import-head h1,
.vm-import-head p {
    margin: 0;
}

.vm-import-head__eyebrow {
    font-size: var(--vt-fs-caption);
    color: var(--vt-text-muted);
    text-transform: uppercase;
}

.vm-import-head__subtitle {
    color: var(--vt-text-muted);
}

.vm-import-wizard {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-6);
    min-width: 0;
}

.vm-import-counter {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}
</style>
