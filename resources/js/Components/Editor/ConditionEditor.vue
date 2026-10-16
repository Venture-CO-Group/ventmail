<script setup lang="ts">
import { VtField, VtInput, VtSelect } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EdSwitch from './EdSwitch.vue';
import type { EditorCatalog } from './types';

/*
 | FELTÉTELES BLOKK: a blokk csak annál a címzettnél jelenik meg, aki egy szegmens tagja
 | (vagy nem tagja), illetve akinek egy mezője megfelel a feltételnek. A kiértékelés a
 | küldéskor, címzettenként történik (a szerver `ConditionalContent`-je).
 */
interface Condition {
    kind: 'segment' | 'field';
    segment?: string;
    field?: string;
    op: string;
    value?: string;
}

const props = defineProps<{ modelValue: Condition | undefined; catalog: EditorCatalog }>();

const emit = defineEmits<{ 'update:modelValue': [value: Condition | undefined] }>();

const { t } = useI18n();

const enabled = computed(() => props.modelValue !== undefined);

const fieldOps = ['equals', 'not_equals', 'contains', 'is_set', 'is_not_set'];

function toggle(on: boolean): void {
    if (!on) {
        emit('update:modelValue', undefined);
        return;
    }

    const segment = props.catalog.segments[0]?.uuid;

    emit(
        'update:modelValue',
        segment ? { kind: 'segment', segment, op: 'in' } : { kind: 'field', field: props.catalog.conditionFields[0] ?? 'contact.first_name', op: 'is_set' },
    );
}

function patch(next: Partial<Condition>): void {
    const current = props.modelValue;

    if (!current) return;

    const merged: Condition = { ...current, ...next };

    if (next.kind === 'segment') {
        emit('update:modelValue', { kind: 'segment', segment: props.catalog.segments[0]?.uuid ?? '', op: 'in' });
        return;
    }

    if (next.kind === 'field') {
        emit('update:modelValue', { kind: 'field', field: props.catalog.conditionFields[0] ?? 'contact.first_name', op: 'is_set' });
        return;
    }

    if (merged.kind === 'field' && ['is_set', 'is_not_set'].includes(merged.op)) {
        delete merged.value;
    }

    emit('update:modelValue', merged);
}

const kindOptions = computed(() => [
    { value: 'segment', label: t('editor.condition.kindSegment'), disabled: props.catalog.segments.length === 0 },
    { value: 'field', label: t('editor.condition.kindField') },
]);
</script>

<template>
    <div class="vm-ed-fields" data-testid="condition-editor">
        <EdSwitch :model-value="enabled" :label="t('editor.condition.title')" :hint="t('editor.condition.hint')" testid="condition-toggle" @update:model-value="toggle" />

        <template v-if="modelValue">
            <VtField :label="t('editor.condition.kind')">
                <VtSelect :model-value="modelValue.kind" :options="kindOptions" data-testid="condition-kind" @update:model-value="patch({ kind: String($event) as Condition['kind'] })" />
            </VtField>

            <template v-if="modelValue.kind === 'segment'">
                <VtField :label="t('editor.condition.segment')">
                    <VtSelect
                        :model-value="modelValue.segment ?? ''"
                        :options="catalog.segments.map((segment) => ({ value: segment.uuid, label: segment.name }))"
                        data-testid="condition-segment"
                        @update:model-value="patch({ segment: String($event) })"
                    />
                </VtField>
                <VtField :label="t('editor.condition.op')">
                    <VtSelect
                        :model-value="modelValue.op"
                        :options="[
                            { value: 'in', label: t('editor.condition.ops.in') },
                            { value: 'not_in', label: t('editor.condition.ops.not_in') },
                        ]"
                        @update:model-value="patch({ op: String($event) })"
                    />
                </VtField>
            </template>

            <template v-else>
                <VtField :label="t('editor.condition.field')">
                    <VtSelect
                        :model-value="modelValue.field ?? ''"
                        :options="catalog.conditionFields.map((field) => ({ value: field, label: field }))"
                        data-testid="condition-field"
                        @update:model-value="patch({ field: String($event) })"
                    />
                </VtField>
                <VtField :label="t('editor.condition.op')">
                    <VtSelect
                        :model-value="modelValue.op"
                        :options="fieldOps.map((op) => ({ value: op, label: t(`editor.condition.ops.${op}`) }))"
                        data-testid="condition-op"
                        @update:model-value="patch({ op: String($event) })"
                    />
                </VtField>
                <VtField v-if="!['is_set', 'is_not_set'].includes(modelValue.op)" :label="t('editor.condition.value')">
                    <VtInput :model-value="modelValue.value ?? ''" data-testid="condition-value" @update:model-value="patch({ value: String($event) })" />
                </VtField>
            </template>
        </template>
    </div>
</template>
