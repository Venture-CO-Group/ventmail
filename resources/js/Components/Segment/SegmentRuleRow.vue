<script setup lang="ts">
import { VtButton, VtInput, VtSelect } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { DAY_OPERATORS, VALUELESS, type NamedRef, type SegmentCatalog, type SegmentRule } from './types';

/**
 * EGY FELTÉTEL: mező → operátor → érték. A választható operátorok a mező TÍPUSÁBÓL jönnek
 * (szerver-katalógus), az érték-mező pedig az operátorból (napok száma, dátum, lista…).
 */
const props = defineProps<{
    rule: SegmentRule;
    catalog: SegmentCatalog;
    lists: NamedRef[];
    tags: NamedRef[];
    path: string;
}>();

const emit = defineEmits<{ (e: 'remove'): void; (e: 'change'): void }>();

const { t, te } = useI18n();

const field = computed(() => props.catalog.fields.find((item) => item.key === props.rule.field) ?? props.catalog.fields[0]);

const fieldLabel = (key: string, label?: string): string => label ?? (te(`segments.field.${key}`) ? t(`segments.field.${key}`) : key);

const fieldOptions = computed(() =>
    props.catalog.fields.map((item) => ({
        value: item.key,
        label: `${t(`segments.fieldGroup.${item.group}`)} · ${fieldLabel(item.key, item.label)}`,
    })),
);

const operatorOptions = computed(() =>
    (props.catalog.operators[field.value.type] ?? []).map((op) => ({ value: op, label: t(`segments.op.${op}`) })),
);

const valueKind = computed<'none' | 'days' | 'enum' | 'list' | 'tag' | 'boolean' | 'date' | 'number' | 'text'>(() => {
    if (VALUELESS.includes(props.rule.op)) return 'none';
    if (DAY_OPERATORS.includes(props.rule.op)) return 'days';

    switch (field.value.type) {
        case 'enum':
            return 'enum';
        case 'list':
            return 'list';
        case 'tag':
            return 'tag';
        case 'boolean':
            return 'boolean';
        case 'date':
            return 'date';
        case 'number':
            return 'number';
        default:
            return 'text';
    }
});

const enumOptions = computed(() =>
    (field.value.values ?? []).map((value) => ({ value, label: t(`segments.value.${field.value.key}.${value}`) })),
);

const refOptions = (items: NamedRef[]) => [{ value: '', label: t('segments.choose') }, ...items.map((item) => ({ value: item.uuid, label: item.name }))];

function setField(key: string): void {
    const next = props.catalog.fields.find((item) => item.key === key);

    props.rule.field = key;
    props.rule.op = props.catalog.operators[next?.type ?? 'string']?.[0] ?? 'is';
    props.rule.value = next?.type === 'enum' ? (next.values?.[0] ?? '') : DAY_OPERATORS.includes(props.rule.op) ? 30 : '';
    emit('change');
}

function setOp(op: string): void {
    props.rule.op = op;

    if (DAY_OPERATORS.includes(op)) props.rule.value = 30;
    else if (VALUELESS.includes(op)) props.rule.value = null;
    emit('change');
}

function setValue(value: string | number): void {
    props.rule.value = value;
    emit('change');
}
</script>

<template>
    <div class="vm-rule" :data-testid="`rule-${path}`">
        <label class="vm-visually-hidden" :for="`rule-${path}-field`">{{ t('segments.fieldLabel') }}</label>
        <VtSelect
            :id="`rule-${path}-field`"
            class="vm-rule__field"
            :model-value="rule.field"
            :options="fieldOptions"
            :data-testid="`rule-field-${path}`"
            @update:model-value="setField(String($event))"
        />

        <label class="vm-visually-hidden" :for="`rule-${path}-op`">{{ t('segments.operatorLabel') }}</label>
        <VtSelect
            :id="`rule-${path}-op`"
            class="vm-rule__op"
            :model-value="rule.op"
            :options="operatorOptions"
            :data-testid="`rule-op-${path}`"
            @update:model-value="setOp(String($event))"
        />

        <div class="vm-rule__value">
            <label v-if="valueKind !== 'none'" class="vm-visually-hidden" :for="`rule-${path}-value`">{{ t('segments.valueLabel') }}</label>
            <template v-if="valueKind === 'days'">
                <VtInput
                    type="number"
                    :model-value="rule.value ?? ''"
                    :aria-label="t('segments.days')"
                    :id="`rule-${path}-value`"
                :data-testid="`rule-value-${path}`"
                    @update:model-value="setValue($event)"
                />
                <span class="vm-rule__unit">{{ t('segments.daysUnit') }}</span>
            </template>
            <VtSelect
                v-else-if="valueKind === 'enum'"
                :model-value="String(rule.value ?? '')"
                :options="enumOptions"
                :aria-label="t('segments.valueLabel')"
                :id="`rule-${path}-value`"
                :data-testid="`rule-value-${path}`"
                @update:model-value="setValue(String($event))"
            />
            <VtSelect
                v-else-if="valueKind === 'list' || valueKind === 'tag'"
                :model-value="String(rule.value ?? '')"
                :options="refOptions(valueKind === 'list' ? lists : tags)"
                :aria-label="t('segments.valueLabel')"
                :id="`rule-${path}-value`"
                :data-testid="`rule-value-${path}`"
                @update:model-value="setValue(String($event))"
            />
            <VtSelect
                v-else-if="valueKind === 'boolean'"
                :model-value="String(rule.value ?? 'true')"
                :options="[
                    { value: 'true', label: t('segments.yes') },
                    { value: 'false', label: t('segments.no') },
                ]"
                :aria-label="t('segments.valueLabel')"
                :id="`rule-${path}-value`"
                :data-testid="`rule-value-${path}`"
                @update:model-value="setValue(String($event))"
            />
            <VtInput
                v-else-if="valueKind !== 'none'"
                :type="valueKind === 'date' ? 'date' : valueKind === 'number' ? 'number' : 'text'"
                :model-value="rule.value ?? ''"
                :aria-label="t('segments.valueLabel')"
                :id="`rule-${path}-value`"
                :data-testid="`rule-value-${path}`"
                @update:model-value="setValue($event)"
            />
        </div>

        <VtButton variant="ghost" size="sm" :data-testid="`rule-remove-${path}`" :aria-label="t('segments.removeRule')" @click="emit('remove')">
            {{ t('segments.remove') }}
        </VtButton>
    </div>
</template>

<style scoped>
.vm-rule {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(calc(calc(var(--vt-space-8) * 4) * 1.25), 1fr));
    align-items: center;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-rule__value {
    display: flex;
    align-items: center;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-rule__unit {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
    white-space: nowrap;
}
</style>
