<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBanner, VtButton, VtEmptyState, VtField, VtInput, VtPageHeader, type VtColumn } from '@venture-co-group/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * CÍMELLENŐRZÉS. A legnagyobb üzleti értékű mező az ELGÉPELÉS-JAVASLAT: egy `gmial.com`
 * cím a listán örökre bounce-ol, de egy kattintással javítható.
 */
interface Job {
    uuid: string;
    name: string;
    status: string;
    total: number;
    processed: number;
    reason: string | null;
    finishedAt: string | null;
    expiresAt: string | null;
}

interface Verdict {
    address: string;
    result: string;
    risk: number;
    reasons: string[];
    did_you_mean: string | null;
    checks: Record<string, unknown>;
}

const props = defineProps<{ jobs: Job[]; usage: number; verdict: Verdict | null }>();

const { t } = useI18n();
const code = useCodes();
const format = useFormat();

const email = ref('');

// A VERDIKT oldal-prop: az Inertia a POST válaszát oldalként jeleníti meg, tehát az
// eredmény ott van, ahol a kérdést feltették — és egy frissítés (GET) után eltűnik.
const verdict = computed(() => props.verdict);

type Row = { name: string; status: string; progress: string; expires: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('validation.job') },
    { key: 'status', label: t('validation.status') },
    { key: 'progress', label: t('validation.progress') },
    { key: 'expires', label: t('validation.expires') },
];

const rows = computed<Row[]>(() =>
    props.jobs.map((job) => ({
        name: job.name,
        status: code('status', job.status),
        progress: `${job.processed}/${job.total}`,
        // A LEJÁRAT nem részletkérdés: a feltöltött lista az ügyfél teljes címlistája
        // lehet, és nincs jogalapunk megőrizni az ellenőrzés után.
        expires: job.expiresAt ? format.date(job.expiresAt) : '—',
    })),
);

function check(): void {
    if (!email.value.trim()) return;

    router.post('/validation', { email: email.value.trim() }, { preserveScroll: true });
}

function useSuggestion(suggestion: string): void {
    email.value = suggestion;
    check();
}
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('nav.settings')" :title="t('validation.title')" :description="t('page.validation.lead')" />

        <VtBanner tone="info" role="status">{{ t('validation.probeNote') }}</VtBanner>

        <VmCard :title="t('validation.single')">
            <VmForm @submit.prevent="check">
                <VmFormSection>
                    <VtField :label="t('validation.address')">
                        <VtInput v-model="email" data-testid="validation-email" placeholder="cimzett@pelda.hu" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!email.trim()" data-testid="validation-submit">
                        {{ t('validation.check') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard v-if="verdict" data-testid="validation-verdict">
            <p>
                <span class="vt-mono vm-wrap">{{ verdict.address }}</span>
                — <strong data-testid="verdict-result">{{ verdict.result }}</strong>
                ({{ t('validation.risk') }}: {{ verdict.risk }})
            </p>

            <p v-if="verdict.reasons.length" data-testid="verdict-reasons">
                {{ t('validation.reasons') }}: {{ verdict.reasons.join(', ') }}
            </p>

            <!-- AZ ELGÉPELÉS-JAVASLAT egy kattintással újraellenőrizhető: ez menti meg a
                 címet, ami különben örökre bounce-olna. -->
            <div v-if="verdict.did_you_mean" class="vm-cluster">
                <span data-testid="verdict-suggestion">{{ t('validation.didYouMean', { address: verdict.did_you_mean }) }}</span>
                <VtButton variant="ghost" data-testid="verdict-use-suggestion" @click="useSuggestion(verdict.did_you_mean)">
                    {{ t('validation.useSuggestion') }}
                </VtButton>
            </div>
        </VmCard>

        <VmCard :title="t('validation.bulk')">
            <p>{{ t('validation.usage', { count: usage }) }}</p>

            <VtEmptyState v-if="!rows.length" :title="t('validation.noJobs')" icon="inbox" data-testid="jobs-empty" />
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('validation.bulk')" data-testid="jobs-table" />
        </VmCard>
    </AppLayout>
</template>
