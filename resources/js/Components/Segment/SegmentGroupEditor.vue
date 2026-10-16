<script setup lang="ts">
import { VtButton, VtSegmented } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SegmentRuleRow from './SegmentRuleRow.vue';
import { blankRule, isGroup, type NamedRef, type SegmentCatalog, type SegmentGroup } from './types';

/**
 * FELTÉTEL-CSOPORT (rekurzív): „mind" (ÉS) vagy „bármelyik" (VAGY) illesztés, szabályokkal
 * és beágyazott csoportokkal. A mélység és a szabályszám plafonját a szerver katalógusa
 * adja — a gombok a plafonon letiltódnak, a szerver pedig így is elutasítaná.
 */
const props = defineProps<{
    group: SegmentGroup;
    catalog: SegmentCatalog;
    lists: NamedRef[];
    tags: NamedRef[];
    depth: number;
    path: string;
    canAddRule: boolean;
}>();

const emit = defineEmits<{ (e: 'change'): void; (e: 'remove'): void }>();

const { t } = useI18n();

const matchOptions = computed(() => [
    { value: 'all', label: t('segments.matchAll') },
    { value: 'any', label: t('segments.matchAny') },
]);

const childPath = (index: number): string => (props.path === '' ? String(index) : `${props.path}-${index}`);

function setMatch(value: string): void {
    props.group.match = value === 'any' ? 'any' : 'all';
    emit('change');
}

function addRule(): void {
    props.group.rules.push(blankRule(props.catalog));
    emit('change');
}

function addGroup(): void {
    props.group.rules.push({ group: { match: 'any', rules: [blankRule(props.catalog)] } });
    emit('change');
}

function removeAt(index: number): void {
    props.group.rules.splice(index, 1);
    emit('change');
}
</script>

<template>
    <fieldset class="vm-seg-group" :class="{ 'vm-seg-group--nested': depth > 1 }" :data-testid="`segment-group-${path || 'root'}`">
        <legend class="vm-seg-group__legend">
            {{ depth > 1 ? t('segments.nestedGroup') : t('segments.conditions') }}
        </legend>

        <div class="vm-seg-group__head">
            <div class="vm-seg-group__match" :data-testid="`segment-match-${path || 'root'}`">
                <VtSegmented :model-value="group.match" :options="matchOptions" :label="t('segments.matchLabel')" @update:model-value="setMatch" />
            </div>

            <VtButton
                v-if="depth > 1"
                variant="ghost"
                size="sm"
                :data-testid="`segment-group-remove-${path}`"
                @click="emit('remove')"
            >
                {{ t('segments.removeGroup') }}
            </VtButton>
        </div>

        <p v-if="!group.rules.length" class="vm-seg-group__empty">{{ t('segments.everyone') }}</p>

        <template v-for="(rule, index) in group.rules" :key="index">
            <p v-if="index > 0" class="vm-seg-group__joiner" aria-hidden="true">
                {{ group.match === 'all' ? t('segments.and') : t('segments.or') }}
            </p>

            <SegmentGroupEditor
                v-if="isGroup(rule)"
                :group="rule.group"
                :catalog="catalog"
                :lists="lists"
                :tags="tags"
                :depth="depth + 1"
                :path="childPath(index)"
                :can-add-rule="canAddRule"
                @change="emit('change')"
                @remove="removeAt(index)"
            />
            <SegmentRuleRow
                v-else
                :rule="rule"
                :catalog="catalog"
                :lists="lists"
                :tags="tags"
                :path="childPath(index)"
                @change="emit('change')"
                @remove="removeAt(index)"
            />
        </template>

        <div class="vm-seg-group__actions">
            <VtButton variant="ghost" size="sm" :disabled="!canAddRule" :data-testid="`segment-add-rule-${path || 'root'}`" @click="addRule">
                {{ t('segments.addRule') }}
            </VtButton>
            <VtButton
                v-if="depth < catalog.maxDepth"
                variant="ghost"
                size="sm"
                :disabled="!canAddRule"
                :data-testid="`segment-add-group-${path || 'root'}`"
                @click="addGroup"
            >
                {{ t('segments.addGroup') }}
            </VtButton>
        </div>
    </fieldset>
</template>

<style scoped>
.vm-seg-group {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    margin: 0;
    padding: var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-lg);
    min-width: 0;
}

.vm-seg-group--nested {
    background-color: var(--vt-surface-2);
    border-style: dashed;
}

.vm-seg-group__match {
    max-inline-size: 100%;
    overflow-x: auto;
}

.vm-seg-group__legend {
    padding-inline: var(--vt-space-2);
    font-size: var(--vt-type-caption-size);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
    color: var(--vt-text-secondary);
}

.vm-seg-group__head,
.vm-seg-group__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
}

.vm-seg-group__actions {
    justify-content: flex-start;
}

.vm-seg-group__joiner {
    margin: 0;
    font-size: var(--vt-type-caption-size);
    font-weight: var(--vt-weight-semibold);
    letter-spacing: var(--vt-type-overline-tracking);
    color: var(--vt-accent);
}

.vm-seg-group__empty {
    margin: 0;
    color: var(--vt-text-secondary);
}
</style>
