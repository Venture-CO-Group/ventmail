<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import { DRAG_MIME, ICONS } from './catalog';

/*
 | A CSOMÓPONT-PALETTA: húzás a vászonra (HTML5 DnD), vagy — billentyűzettel és kattintással
 | — hozzáadás a látható terület közepére. A húzás nem lehet az egyetlen út (akadálymentesség).
 */
defineProps<{ triggers: string[]; actions: string[]; hasTrigger: boolean }>();

const emit = defineEmits<{ add: [type: string] }>();

const { t } = useI18n();

function onDragStart(event: DragEvent, type: string): void {
    event.dataTransfer?.setData(DRAG_MIME, type);
    event.dataTransfer?.setData('text/plain', type);

    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}
</script>

<template>
    <nav class="vm-jpal" :aria-label="t('journeyCanvas.palette.label')" data-testid="journey-palette">
        <p class="vm-jpal__group">{{ t('journeyCanvas.palette.triggers') }}</p>
        <button
            v-for="type in triggers"
            :key="type"
            type="button"
            class="vm-jpal__item"
            draggable="true"
            :disabled="hasTrigger"
            :title="hasTrigger ? t('journeyCanvas.palette.oneTrigger') : t('journeyCanvas.palette.hint')"
            :data-testid="`palette-${type}`"
            @dragstart="onDragStart($event, type)"
            @click="emit('add', type)"
        >
            <span class="vm-jpal__icon"><VmIcon :name="ICONS[type] ?? 'plus'" /></span>
            <span class="vm-jpal__label">{{ t(`journeyCanvas.nodes.${type}`) }}</span>
        </button>

        <p class="vm-jpal__group">{{ t('journeyCanvas.palette.actions') }}</p>
        <button
            v-for="type in actions"
            :key="type"
            type="button"
            class="vm-jpal__item"
            draggable="true"
            :title="t('journeyCanvas.palette.hint')"
            :data-testid="`palette-${type}`"
            @dragstart="onDragStart($event, type)"
            @click="emit('add', type)"
        >
            <span class="vm-jpal__icon" :class="{ 'vm-jpal__icon--end': type === 'exit' || type === 'goal' }"><VmIcon :name="ICONS[type] ?? 'plus'" /></span>
            <span class="vm-jpal__label">{{ t(`journeyCanvas.nodes.${type}`) }}</span>
        </button>
    </nav>
</template>
