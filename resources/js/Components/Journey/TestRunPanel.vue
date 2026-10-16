<script setup lang="ts">
import { VtBanner, VtButton } from '@venture-co-group/ui';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import { formatMinutes, type TestRunResult } from './catalog';

/*
 | A TESZT-FUTTATÁS eredménye: a kontakt útja lépésenként, a SZIMULÁLT idővel. A fejléc
 | kimondja: gyorsított idő, VALÓDI KÜLDÉS NÉLKÜL — a „kimenne" a kapuk mellékhatás nélküli
 | ellenőrzése, nem egy elküldött levél.
 */
defineProps<{ result: TestRunResult; titleOf: (node: string | null) => string }>();

const emit = defineEmits<{ close: []; focus: [node: string] }>();

const { t } = useI18n();
</script>

<template>
    <section class="vm-jtest" data-testid="test-run-panel" :aria-label="t('journeyCanvas.test.title')">
        <header class="vm-jtest__head">
            <strong>{{ t('journeyCanvas.test.title') }}</strong>
            <VtButton variant="ghost" size="sm" :aria-label="t('journeyCanvas.test.close')" data-testid="test-run-close" @click="emit('close')"><VmIcon name="close" /></VtButton>
        </header>

        <p class="vm-jtest__note">{{ t('journeyCanvas.test.note', { contact: result.contact }) }}</p>

        <VtBanner v-if="result.stopped" tone="warning" role="status" data-testid="test-run-stopped">
            {{ t(`journeyCanvas.test.stopped.${result.stopped}`) }}
        </VtBanner>

        <ol class="vm-jtest__steps">
            <li v-for="(step, index) in result.steps" :key="index" class="vm-jtest__step" :class="`vm-jtest__step--${step.outcome}`" :data-testid="`test-step-${index}`" :data-outcome="step.outcome">
                <button v-if="step.node" type="button" class="vm-jtest__node" @click="emit('focus', step.node)">{{ titleOf(step.node) }}</button>
                <span v-else class="vm-jtest__node">{{ t('journeyCanvas.test.end') }}</span>
                <span class="vm-jtest__outcome">
                    {{ t(`journeyCanvas.test.outcomes.${step.outcome}`, { detail: step.detail ?? '', handle: step.handle ?? '' }) }}
                </span>
                <span v-if="step.minutes" class="vm-jtest__time">{{ t('journeyCanvas.test.elapsed', { time: formatMinutes(step.minutes, t) }) }}</span>
            </li>
        </ol>

        <p class="vm-jtest__summary" data-testid="test-run-summary">
            {{ t('journeyCanvas.test.summary', { steps: result.steps.length, sends: result.sends, real: result.realSends }) }}
        </p>
    </section>
</template>
