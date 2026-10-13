<script setup lang="ts">
import { VtField, VtInput, VtSelect } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Config, Options } from './catalog';

/*
 | FELTÉTEL-MEZŐK (if/else, többágú ág, cél): típus + a típus paraméterei. Ugyanazok a
 | feltétel-típusok, mint a motorban (`JourneyConditions`).
 */
const props = defineProps<{ modelValue: Config; types: string[]; options: Options; testid: string; allowNone?: boolean }>();

const emit = defineEmits<{ 'update:modelValue': [value: Config] }>();

const { t } = useI18n();

const type = computed(() => String(props.modelValue.type ?? ''));

const typeOptions = computed(() => [
    ...(props.allowNone ? [{ value: '', label: t('journeyCanvas.config.none') }] : []),
    ...props.types.map((value) => ({ value, label: t(`journeyCanvas.conditions.${value}`) })),
]);

function set(key: string, value: unknown): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

function setType(value: string | number): void {
    const next = String(value);

    emit('update:modelValue', next === '' ? {} : { type: next, ...(next.endsWith('_email') ? { within_hours: 168 } : {}) });
}
</script>

<template>
    <div class="vm-jcfg__group">
        <VtField :label="t('journeyCanvas.config.conditionType')">
            <VtSelect :model-value="type" :options="typeOptions" :data-testid="`${testid}-type`" @update:model-value="setType" />
        </VtField>

        <VtField v-if="type === 'in_segment'" :label="t('journeyCanvas.config.segment')">
            <VtSelect
                :model-value="String(modelValue.segment ?? '')"
                :options="options.segments"
                :placeholder="t('journeyCanvas.config.choose')"
                :data-testid="`${testid}-segment`"
                @update:model-value="set('segment', String($event))"
            />
        </VtField>

        <VtField v-if="type === 'has_tag'" :label="t('journeyCanvas.config.tag')">
            <VtSelect
                :model-value="String(modelValue.tag ?? '')"
                :options="options.tags"
                :placeholder="t('journeyCanvas.config.choose')"
                :data-testid="`${testid}-tag`"
                @update:model-value="set('tag', String($event))"
            />
        </VtField>

        <VtField v-if="type === 'opened_email' || type === 'clicked_email'" :label="t('journeyCanvas.config.withinHours')">
            <VtInput
                type="number"
                :model-value="Number(modelValue.within_hours ?? 168)"
                :data-testid="`${testid}-hours`"
                @update:model-value="set('within_hours', Number($event))"
            />
        </VtField>

        <template v-if="type === 'context_equals' || type === 'field_equals'">
            <VtField v-if="type === 'field_equals'" :label="t('journeyCanvas.config.field')">
                <VtSelect
                    :model-value="String(modelValue.key ?? '')"
                    :options="options.fields"
                    :placeholder="t('journeyCanvas.config.choose')"
                    :data-testid="`${testid}-key`"
                    @update:model-value="set('key', String($event))"
                />
            </VtField>
            <VtField v-else :label="t('journeyCanvas.config.contextKey')">
                <VtInput :model-value="String(modelValue.key ?? '')" :data-testid="`${testid}-key`" @update:model-value="set('key', $event)" />
            </VtField>
            <VtField :label="t('journeyCanvas.config.value')">
                <VtInput :model-value="String(modelValue.value ?? '')" :data-testid="`${testid}-value`" @update:model-value="set('value', $event)" />
            </VtField>
        </template>
    </div>
</template>
