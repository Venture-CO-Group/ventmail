<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtCheckbox, VtField, VtInput, VtSelect } from '@venture/ui';
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
        variants: Array<{ uuid: string; label: string; subject: string; share: number; winner: boolean }>;
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
        <h1 class="vt-text-display">{{ campaign.name }}</h1>

        <p class="vm-banner" role="status" data-testid="campaign-status">
            {{ t('campaigns.statusIs', { status: campaign.status }) }}
        </p>

        <!-- A ZÁROLT ÁLLAPOT OKA kiírva: küldés közben a célzás átírása fél kampányt
             küldene máshova. -->
        <p v-if="!campaign.editable" class="vm-banner vm-banner--warning" role="status" data-testid="campaign-locked">
            {{ t('campaigns.lockedReason') }}
        </p>

        <VtCard>
            <h2>{{ t('campaigns.content') }}</h2>

            <VtField :label="t('campaigns.name')">
                <VtInput v-model="form.name" :disabled="!campaign.editable" data-testid="edit-name" />
            </VtField>

            <VtField :label="t('campaigns.subject')">
                <VtInput v-model="form.subject" :disabled="!campaign.editable" data-testid="edit-subject" />
            </VtField>

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
        </VtCard>

        <VtCard>
            <h2>{{ t('campaigns.targeting') }}</h2>

            <!-- A BECSLÉS a VALÓDI feloldón megy át: ugyanazon, amelyik a küldéskor dolgozik. -->
            <p data-testid="campaign-estimate">{{ t('campaigns.estimate', { count: estimate }) }}</p>

            <fieldset>
                <legend>{{ t('campaigns.lists') }}</legend>
                <div class="vm-cluster">
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
                </div>
            </fieldset>

            <fieldset>
                <legend>{{ t('campaigns.segments') }}</legend>
                <div class="vm-cluster">
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
                </div>
            </fieldset>

            <VtButton :disabled="!campaign.editable" data-testid="campaign-save" @click="save">
                {{ t('campaigns.save') }}
            </VtButton>
        </VtCard>

        <VtCard>
            <h2>{{ t('campaigns.scheduling') }}</h2>

            <VtField :label="t('campaigns.localTime')" :hint="t('campaigns.localTimeHint')">
                <VtInput v-model="localTime" data-testid="schedule-time" placeholder="2026-10-01 09:00" />
            </VtField>

            <VtField :label="t('campaigns.timezone')">
                <VtInput v-model="timezone" data-testid="schedule-timezone" />
            </VtField>

            <div class="vm-cluster">
                <VtButton data-testid="action-schedule" @click="transition('schedule')">{{ t('campaigns.schedule') }}</VtButton>
                <VtButton variant="ghost" data-testid="action-unschedule" @click="transition('unschedule')">{{ t('campaigns.unschedule') }}</VtButton>
                <VtButton data-testid="action-start" @click="transition('start')">{{ t('campaigns.start') }}</VtButton>
                <VtButton variant="ghost" data-testid="action-pause" @click="transition('pause')">{{ t('campaigns.pause') }}</VtButton>
                <VtButton variant="ghost" data-testid="action-resume" @click="transition('resume')">{{ t('campaigns.resume') }}</VtButton>
            </div>
        </VtCard>

        <VtCard v-if="campaign.variants.length" data-testid="campaign-variants">
            <h2>{{ t('campaigns.abTest') }}</h2>

            <div v-for="variant in campaign.variants" :key="variant.uuid" class="vm-cluster">
                <strong>{{ variant.label }}</strong>
                <span>{{ variant.subject }}</span>
                <span>{{ variant.share }}%</span>
                <span v-if="variant.winner" :data-testid="`winner-${variant.uuid}`">{{ t('campaigns.winner') }}</span>
            </div>
        </VtCard>
    </AppLayout>
</template>
