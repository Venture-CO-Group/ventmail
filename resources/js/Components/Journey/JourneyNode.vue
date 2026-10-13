<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core';
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import { CanvasKey, ICONS, isTrigger, outputLabel, outputs, summarize, type Config } from './catalog';

/*
 | EGY CSOMÓPONT a vásznon (v1.1 · 6. modul): ikon, cím, összefoglaló, kimenetek (jobbra,
 | felirattal), bemenet (balra — a triggernek nincs), és a futási statisztika (belépett · vár
 | · továbblépett). A hibás csomópont jelölt; a teszt-futás útja kiemelt.
 |
 | JELENTÉS: hiányzó @venture/ui komponens: folyamat-csomópont (node card) és vászon.
 */
const props = defineProps<{ id: string; data: { kind: string; label: string; config: Config }; selected?: boolean }>();

const { t } = useI18n();
const canvas = inject(CanvasKey)!;

const trigger = computed(() => isTrigger(props.data.kind));
const title = computed(() => props.data.label || t(`journeyCanvas.nodes.${props.data.kind}`));
const summary = computed(() => summarize(props.data.kind, props.data.config, canvas.options.value, t));
const handles = computed(() => outputs(props.data.kind, props.data.config).map((handle) => ({ id: handle, label: outputLabel(handle, props.data.config, t) })));
const stats = computed(() => canvas.stats.value[props.id] ?? null);
const severity = computed(() => {
    const own = canvas.issues.value.filter((issue) => issue.node === props.id);

    if (own.some((issue) => issue.severity === 'error')) return 'error';

    return own.length ? 'warning' : null;
});
const onPath = computed(() => canvas.testPath.value.includes(props.id));
</script>

<template>
    <div
        class="vm-jn"
        :class="[`vm-jn--${trigger ? 'trigger' : 'action'}`, { 'is-selected': selected, 'is-on-path': onPath, [`has-${severity}`]: severity }]"
        :data-testid="`jnode-${id}`"
        :data-kind="data.kind"
        :data-severity="severity ?? undefined"
    >
        <Handle v-if="!trigger" type="target" :position="Position.Left" id="in" class="vm-jn__handle vm-jn__handle--in" />

        <div class="vm-jn__head">
            <span class="vm-jn__icon"><VmIcon :name="ICONS[data.kind] ?? 'plus'" /></span>
            <span class="vm-jn__text">
                <span class="vm-jn__title">{{ title }}</span>
                <span v-if="summary" class="vm-jn__summary">{{ summary }}</span>
            </span>
            <span v-if="severity" class="vm-jn__flag" :title="t(`journeyCanvas.severity.${severity}`)"><VmIcon name="warning" /></span>
        </div>

        <ul v-if="handles.length" class="vm-jn__outputs">
            <li v-for="output in handles" :key="output.id" class="vm-jn__output">
                <span class="vm-jn__output-label">{{ output.label }}</span>
                <Handle
                    type="source"
                    :position="Position.Right"
                    :id="output.id"
                    class="vm-jn__handle vm-jn__handle--out"
                    :data-testid="`handle-${id}-${output.id}`"
                />
            </li>
        </ul>

        <div v-if="stats" class="vm-jn__stats" :aria-label="t('journeyCanvas.stats.label')">
            <span :title="t('journeyCanvas.stats.entered')"><VmIcon name="users" />{{ stats.entered }}</span>
            <span class="vm-jn__stat--waiting" :title="t('journeyCanvas.stats.waiting')"><VmIcon name="history" />{{ stats.waiting }}</span>
            <span class="vm-jn__stat--moved" :title="t('journeyCanvas.stats.movedOn')"><VmIcon name="check" />{{ stats.movedOn }}</span>
        </div>
    </div>
</template>
