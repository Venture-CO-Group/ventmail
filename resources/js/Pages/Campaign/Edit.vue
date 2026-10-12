<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCheckbox, VtField, VtInput, VtSelect } from '@venture/ui';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmFieldGroup from '../../Components/Form/VmFieldGroup.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * KAMPÁNY-SZERKESZTŐ: tartalom, célzás, ütemezés, indítás.
 *
 * A KÜLDÉS KÖZBEN a célzás nem írható át (a modell tiltja) — a felület ezt nem trükközi
 * ki, hanem megmutatja: a mezők letiltva, az ok kiírva. Fél kampányt máshova küldeni
 * rosszabb, mint nem tudni szerkeszteni.
 */
const props = defineProps<{
    campaign: {
        uuid: string;
        name: string;
        subject: string;
        preheader: string | null;
        fromEmail: string | null;
        fromName: string | null;
        status: string;
        editable: boolean;
        templateUuid: string | null;
        targetLists: string[];
        targetSegments: string[];
        excludeLists: string[];
        excludeSegments: string[];
        scheduledAt: string | null;
        timezone: string | null;
        variantWeightTotal: number;
        variants: Array<{
            uuid: string;
            label: string;
            subject: string;
            weight: number;
            winner: boolean;
            sent: number;
            opened: number;
            clicked: number;
        }>;
    };
    templates: Array<{ uuid: string; name: string }>;
    lists: Array<{ uuid: string; name: string }>;
    segments: Array<{ uuid: string; name: string }>;
    estimate: number;
}>();

const { t } = useI18n();

const form = ref({
    name: props.campaign.name,
    subject: props.campaign.subject,
    preheader: props.campaign.preheader ?? '',
    fromEmail: props.campaign.fromEmail ?? '',
    fromName: props.campaign.fromName ?? '',
    templateUuid: props.campaign.templateUuid ?? '',
    targetLists: [...props.campaign.targetLists],
    targetSegments: [...props.campaign.targetSegments],
    excludeLists: [...props.campaign.excludeLists],
    excludeSegments: [...props.campaign.excludeSegments],
});

const variantForm = ref({ label: '', subject: '', weight: '50' });
const localTime = ref('');
const timezone = ref(props.campaign.timezone ?? 'Europe/Budapest');

const templateOptions = computed(() => [
    { value: '', label: t('campaigns.noTemplate') },
    ...props.templates.map((template) => ({ value: template.uuid, label: template.name })),
]);

function toggleTarget(kind: 'targetLists' | 'targetSegments' | 'excludeLists' | 'excludeSegments', uuid: string): void {
    const current = form.value[kind];

    form.value[kind] = current.includes(uuid) ? current.filter((item) => item !== uuid) : [...current, uuid];
}

function save(): void {
    router.put(`/campaigns/${props.campaign.uuid}`, form.value, { preserveScroll: true });
}

function addVariant(): void {
    router.post(
        `/campaigns/${props.campaign.uuid}/variants`,
        { ...variantForm.value, weight: Number(variantForm.value.weight) },
        {
            preserveScroll: true,
            onSuccess: () => {
                variantForm.value = { label: '', subject: '', weight: '50' };
            },
        },
    );
}

function removeVariant(uuid: string): void {
    router.delete(`/campaigns/${props.campaign.uuid}/variants/${uuid}`, { preserveScroll: true });
}

function transition(action: string): void {
    router.post(
        `/campaigns/${props.campaign.uuid}/transition`,
        { action, localTime: localTime.value || null, timezone: timezone.value || null },
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display vm-wrap">{{ campaign.name }}</h1>

        <p class="vm-banner" role="status" data-testid="campaign-status">
            {{ t('campaigns.statusIs', { status: campaign.status }) }}
        </p>

        <!-- A ZÁROLT ÁLLAPOT OKA kiírva: küldés közben a célzás átírása fél kampányt
             küldene máshova. -->
        <p v-if="!campaign.editable" class="vm-banner vm-banner--warning" role="status" data-testid="campaign-locked">
            {{ t('campaigns.lockedReason') }}
        </p>

        <VmCard>
            <h2>{{ t('campaigns.content') }}</h2>

            <VmFormSection>
                <VmFormRow>
                    <VtField :label="t('campaigns.name')">
                        <VtInput v-model="form.name" :disabled="!campaign.editable" data-testid="edit-name" />
                    </VtField>

                    <VtField :label="t('campaigns.subject')">
                        <VtInput v-model="form.subject" :disabled="!campaign.editable" data-testid="edit-subject" />
                    </VtField>
                </VmFormRow>

                <VmFormRow>
                    <VtField :label="t('campaigns.fromEmail')" :hint="t('campaigns.fromHint')">
                        <VtInput v-model="form.fromEmail" :disabled="!campaign.editable" data-testid="edit-from" />
                    </VtField>

                    <VtField :label="t('campaigns.template')">
                        <VtSelect
                            v-model="form.templateUuid"
                            :options="templateOptions"
                            :disabled="!campaign.editable"
                            data-testid="edit-template"
                        />
                    </VtField>
                </VmFormRow>
            </VmFormSection>
        </VmCard>

        <VmCard>
            <h2>{{ t('campaigns.targeting') }}</h2>

            <!-- A BECSLÉS a VALÓDI feloldón megy át: ugyanazon, amelyik a küldéskor dolgozik. -->
            <p data-testid="campaign-estimate">{{ t('campaigns.estimate', { count: estimate }) }}</p>

            <VmForm @submit.prevent="save">
                <VmFormSection>
                    <VmFieldGroup :legend="t('campaigns.lists')">
                        <VtCheckbox
                            v-for="list in lists"
                            :key="list.uuid"
                            :model-value="form.targetLists.includes(list.uuid)"
                            :disabled="!campaign.editable"
                            :data-testid="`target-list-${list.uuid}`"
                            @update:model-value="toggleTarget('targetLists', list.uuid)"
                        >
                            {{ list.name }}
                        </VtCheckbox>
                    </VmFieldGroup>

                    <VmFieldGroup :legend="t('campaigns.segments')">
                        <VtCheckbox
                            v-for="segment in segments"
                            :key="segment.uuid"
                            :model-value="form.targetSegments.includes(segment.uuid)"
                            :disabled="!campaign.editable"
                            :data-testid="`target-segment-${segment.uuid}`"
                            @update:model-value="toggleTarget('targetSegments', segment.uuid)"
                        >
                            {{ segment.name }}
                        </VtCheckbox>
                    </VmFieldGroup>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!campaign.editable" data-testid="campaign-save">
                        {{ t('campaigns.save') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <h2>{{ t('campaigns.scheduling') }}</h2>

            <VmFormSection>
                <VmFormRow>
                    <VtField :label="t('campaigns.localTime')" :hint="t('campaigns.localTimeHint')">
                        <VtInput v-model="localTime" data-testid="schedule-time" placeholder="2026-10-01 09:00" />
                    </VtField>

                    <VtField :label="t('campaigns.timezone')">
                        <VtInput v-model="timezone" data-testid="schedule-timezone" />
                    </VtField>
                </VmFormRow>
            </VmFormSection>

            <VmFormActions>
                <VtButton data-testid="action-schedule" @click="transition('schedule')">{{ t('campaigns.schedule') }}</VtButton>
                <VtButton variant="ghost" data-testid="action-unschedule" @click="transition('unschedule')">{{ t('campaigns.unschedule') }}</VtButton>
                <VtButton data-testid="action-start" @click="transition('start')">{{ t('campaigns.start') }}</VtButton>
                <VtButton variant="ghost" data-testid="action-pause" @click="transition('pause')">{{ t('campaigns.pause') }}</VtButton>
                <VtButton variant="ghost" data-testid="action-resume" @click="transition('resume')">{{ t('campaigns.resume') }}</VtButton>
            </VmFormActions>
        </VmCard>

        <VmCard data-testid="campaign-variants">
            <h2>{{ t('campaigns.abTest') }}</h2>

            <p>{{ t('campaigns.abHint') }}</p>

            <!-- AZ ÖSSZEG az indítás feltétele (pontosan 100), a „legalább kettő" pedig
                 ugyanaz: egyetlen változat nem A/B teszt. A felhasználó ne az indítás
                 hibaüzenetéből tudja meg. -->
            <p
                v-if="campaign.variants.length"
                class="vm-banner"
                :class="{ 'vm-banner--warning': campaign.variantWeightTotal !== 100 || campaign.variants.length < 2 }"
                data-testid="variant-total"
            >
                {{ t('campaigns.weightTotal', { total: campaign.variantWeightTotal }) }}
                <template v-if="campaign.variants.length === 1">{{ t('campaigns.variantSingle') }}</template>
            </p>

            <!-- A VÁLTOZAT csak DRAFT állapotban vehető fel: a kiosztás a küldés elején
                 dől el, egy menet közben érkező variáns a már kiküldött felével
                 összehasonlíthatatlan lenne. -->
            <VmForm v-if="campaign.editable" @submit.prevent="addVariant">
                <VmFormSection>
                    <VmFormRow>
                        <VtField :label="t('campaigns.variantLabel')">
                            <VtInput v-model="variantForm.label" data-testid="variant-label" placeholder="B" />
                        </VtField>

                        <VtField :label="t('campaigns.weight')" :hint="t('campaigns.weightHint')">
                            <VtInput v-model="variantForm.weight" data-testid="variant-weight" />
                        </VtField>
                    </VmFormRow>

                    <VtField :label="t('campaigns.subject')">
                        <VtInput v-model="variantForm.subject" data-testid="variant-subject" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton
                        type="submit"
                        :disabled="!variantForm.label.trim() || !variantForm.subject.trim()"
                        data-testid="variant-submit"
                    >
                        {{ t('campaigns.addVariant') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>

            <div v-for="variant in campaign.variants" :key="variant.uuid" class="vm-cluster">
                <strong>{{ variant.label }}</strong>
                <span class="vm-truncate vm-truncate--fill" :title="variant.subject">{{ variant.subject }}</span>
                <span>{{ t('campaigns.weight') }}: {{ variant.weight }}</span>
                <span :data-testid="`variant-stats-${variant.label}`">
                    {{ variant.sent }} / {{ variant.opened }} / {{ variant.clicked }}
                </span>

                <VtButton
                    v-if="campaign.editable"
                    variant="ghost"
                    :data-testid="`variant-remove-${variant.label}`"
                    @click="removeVariant(variant.uuid)"
                >
                    {{ t('campaigns.removeVariant') }}
                </VtButton>
                <span v-if="variant.winner" :data-testid="`winner-${variant.uuid}`">{{ t('campaigns.winner') }}</span>
            </div>
        </VmCard>
    </AppLayout>
</template>
