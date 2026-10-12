<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/** KAMPÁNYOK listája. Az állapot az állapotgépből jön — a felület nem találgat. */
const props = defineProps<{
    campaigns: Array<{
        uuid: string;
        name: string;
        subject: string;
        status: string;
        template: string | null;
        scheduledAt: string | null;
        timezone: string | null;
        editable: boolean;
        variants: number;
    }>;
    templates: Array<{ uuid: string; name: string }>;
}>();

const { t } = useI18n();

const name = ref('');
const subject = ref('');

type Row = { name: string; status: string; template: string; scheduled: string; variants: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('campaigns.name') },
    { key: 'status', label: t('campaigns.status') },
    { key: 'template', label: t('campaigns.template') },
    { key: 'scheduled', label: t('campaigns.scheduled') },
    { key: 'variants', label: t('campaigns.variants') },
];

const rows = computed<Row[]>(() =>
    props.campaigns.map((campaign) => ({
        name: campaign.name,
        status: campaign.status,
        template: campaign.template ?? '—',
        // AZ ÜTEMEZÉS IDŐZÓNÁVAL értelmes: „09:00" önmagában nem mond semmit egy
        // nemzetközi listánál.
        scheduled: campaign.scheduledAt
            ? `${new Date(campaign.scheduledAt).toLocaleString()} (${campaign.timezone ?? 'UTC'})`
            : '—',
        variants: campaign.variants ? String(campaign.variants) : '—',
    })),
);

function create(): void {
    if (!name.value.trim() || !subject.value.trim()) return;

    router.post('/campaigns', { name: name.value.trim(), subject: subject.value.trim() });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('campaigns.title') }}</h1>

        <VmCard>
            <h2>{{ t('campaigns.new') }}</h2>

            <VmForm @submit.prevent="create">
                <VmFormSection>
                    <VmFormRow>
                        <VtField :label="t('campaigns.name')">
                            <VtInput v-model="name" data-testid="campaign-name" placeholder="Szeptemberi hírlevél" />
                        </VtField>

                        <VtField :label="t('campaigns.subject')">
                            <VtInput v-model="subject" data-testid="campaign-subject" placeholder="Újdonságok nálunk" />
                        </VtField>
                    </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!name.trim() || !subject.trim()" data-testid="campaign-submit">
                        {{ t('campaigns.create') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <p v-if="!rows.length" data-testid="campaigns-empty">{{ t('campaigns.empty') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('campaigns.title')" data-testid="campaigns-table" />

            <div v-for="campaign in campaigns" :key="campaign.uuid" class="vm-cluster">
                <Link :href="`/campaigns/${campaign.uuid}`" :data-testid="`open-${campaign.uuid}`">
                    {{ campaign.name }}
                </Link>
                <span v-if="!campaign.editable" :data-testid="`locked-${campaign.uuid}`">{{ t('campaigns.locked') }}</span>
            </div>
        </VmCard>
    </AppLayout>
</template>
