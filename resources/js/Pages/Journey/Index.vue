<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtSelect, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useCodes } from '../../composables/useCodes';

/** JOURNEY-K. A futó példányok száma mondja meg, hogy a journey tényleg ÉL-e. */
const props = defineProps<{
    journeys: Array<{ uuid: string; name: string; status: string; trigger: string; steps: number; activeRuns: number }>;
    triggers: string[];
}>();

const { t } = useI18n();
const code = useCodes();

const name = ref('');
const trigger = ref(props.triggers[0] ?? 'api_event');

type Row = { name: string; status: string; trigger: string; steps: string; runs: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('journeys.name') },
    { key: 'status', label: t('journeys.status') },
    { key: 'trigger', label: t('journeys.trigger') },
    { key: 'steps', label: t('journeys.steps') },
    { key: 'runs', label: t('journeys.activeRuns') },
];

const rows = computed<Row[]>(() =>
    props.journeys.map((journey) => ({
        name: journey.name,
        status: code('status', journey.status),
        trigger: code('trigger', journey.trigger),
        steps: String(journey.steps),
        runs: String(journey.activeRuns),
    })),
);

// AZ AKTÍV, DE ÜRES journey konfigurációs hiba: a trigger sosem tüzel, és senki nem veszi
// észre — a szám az egyetlen jel.
const idleActive = computed(() => props.journeys.filter((journey) => journey.status === 'active' && journey.activeRuns === 0).length);

function create(): void {
    if (!name.value.trim()) return;

    router.post('/journeys', { name: name.value.trim(), trigger: trigger.value });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('journeys.title') }}</h1>

        <p v-if="idleActive" class="vm-banner vm-banner--warning" role="status" data-testid="journeys-idle">
            {{ t('journeys.idleWarning', { count: idleActive }) }}
        </p>

        <VmCard>
            <h2>{{ t('journeys.new') }}</h2>

            <VmForm @submit.prevent="create">
                <VmFormSection>
                    <VmFormRow>
                        <VtField :label="t('journeys.name')">
                            <VtInput v-model="name" data-testid="journey-name" placeholder="Üdvözlő sorozat" />
                        </VtField>

                        <VtField :label="t('journeys.trigger')" :hint="t('journeys.triggerHint')">
                            <VtSelect
                                v-model="trigger"
                                :options="triggers.map((value) => ({ value, label: value }))"
                                data-testid="journey-trigger"
                            />
                        </VtField>
                    </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!name.trim()" data-testid="journey-submit">
                        {{ t('journeys.create') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <p v-if="!rows.length" data-testid="journeys-empty">{{ t('journeys.empty') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('journeys.title')" data-testid="journeys-table" />

            <div v-for="journey in journeys" :key="journey.uuid" class="vm-cluster">
                <Link :href="`/journeys/${journey.uuid}`" :data-testid="`open-${journey.uuid}`">{{ journey.name }}</Link>
            </div>
        </VmCard>
    </AppLayout>
</template>
