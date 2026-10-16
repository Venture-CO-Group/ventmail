<script setup lang="ts">
import { VtBanner, VtButton } from '@venture-co-group/ui';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';
import EdSegment from './EdSegment.vue';
import TestSendForm from './TestSendForm.vue';
import type { OptimizeReport } from './types';
import { useFormat } from '../../composables/useFormat';

/*
 | OPTIMALIZÁLÁS: eszköz-nézet (desktop / mobil / sötét), linkek (törött, hiányzó UTM),
 | képek (alt-szöveg, méret), súly (Gmail-vágás 102 KB felett), spam-becslés (a meglévő,
 | szabály-alapú `SpamScorer`), kötelező elemek, és teszt-küldés.
 |
 | A riport a SZERVER fordítóján készül a MENTETLEN állapotból — ugyanaz a HTML, ami
 | kimenne. A linkekre nincs kimenő kérés (lásd `TemplateOptimizer`).
 */
const props = defineProps<{
    uuid: string;
    device: 'desktop' | 'mobile' | 'dark';
    payload: () => Record<string, unknown>;
    recipients: Array<{ email: string; kind: string }>;
    domains: string[];
    beforeSend: () => Promise<boolean>;
}>();

const emit = defineEmits<{ device: [value: 'desktop' | 'mobile' | 'dark']; focus: [path: string]; addUtm: [] }>();

const { t, te } = useI18n();
const format = useFormat();
const report = ref<OptimizeReport | null>(null);
const loading = ref(false);
const failed = ref<string | null>(null);

async function run(): Promise<void> {
    loading.value = true;
    failed.value = null;

    try {
        const response = await axios.post<OptimizeReport>(`/templates/${props.uuid}/optimize`, props.payload());
        report.value = response.data;
    } catch (error) {
        const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
        failed.value = Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? t('editor.optimize.failed');
    } finally {
        loading.value = false;
    }
}

onMounted(run);

defineExpose({ run });

function kb(bytes: number | null | undefined): string {
    return bytes === null || bytes === undefined ? '–' : `${Math.round(bytes / 1024)} KB`;
}

const weightRatio = computed(() => (report.value ? Math.min(100, Math.round((report.value.weight.bytes / report.value.weight.limit) * 100)) : 0));
const spamRatio = computed(() => (report.value ? Math.min(100, Math.round((report.value.spam.score / 10) * 100)) : 0));

function rule(code: string, advice: string): string {
    const key = `editor.optimize.spamRules.${code}`;

    return te(key) ? t(key) : advice;
}

function tone(bad: boolean, warn = false): string {
    return bad ? 'vm-ed-card--bad' : warn ? 'vm-ed-card--warn' : 'vm-ed-card--ok';
}

const deviceOptions = computed(() => [
    { value: 'desktop', label: t('editor.device.desktop'), icon: 'desktop' },
    { value: 'mobile', label: t('editor.device.mobile'), icon: 'mobile' },
    { value: 'dark', label: t('editor.device.dark'), icon: 'moon' },
]);
</script>

<template>
    <div class="vm-ed-fields" data-testid="optimize-panel">
        <p class="vm-ed-heading">{{ t('editor.optimize.preview') }}</p>
        <EdSegment :model-value="device" :options="deviceOptions" :label="t('editor.optimize.preview')" testid="optimize-device" @update:model-value="emit('device', $event as never)" />

        <div class="vm-cluster">
            <VtButton size="sm" variant="ghost" :loading="loading" data-testid="optimize-run" @click="run">{{ t('editor.optimize.rerun') }}</VtButton>
        </div>

        <VtBanner v-if="failed" tone="danger" role="status">{{ failed }}</VtBanner>

        <template v-if="report">
            <article class="vm-ed-card" :class="tone(report.links.broken > 0, report.links.missingUtm > 0)" data-testid="optimize-links">
                <div class="vm-ed-card__head">
                    <h3 class="vm-ed-card__title"><VmIcon :name="report.links.broken ? 'warning' : 'link'" /> {{ t('editor.optimize.links', { count: report.links.total }) }}</h3>
                    <VtButton v-if="report.links.missingUtm" size="sm" variant="ghost" data-testid="optimize-add-utm" @click="emit('addUtm')">{{ t('editor.optimize.addUtm') }}</VtButton>
                </div>
                <p class="vm-ed-hint">{{ t('editor.optimize.linksSummary', { broken: report.links.broken, utm: report.links.missingUtm }) }}</p>
                <ul class="vm-ed-issues">
                    <li v-for="(link, index) in report.links.items.filter((item) => item.issues.length)" :key="index">
                        <button type="button" @click="emit('focus', link.path)">{{ link.url || '—' }}</button>
                        · {{ link.issues.map((issue) => t(`editor.optimize.issues.${issue}`)).join(', ') }}
                    </li>
                </ul>
            </article>

            <article class="vm-ed-card" :class="tone(report.images.missingAlt > 0 || report.images.heavy > 0)" data-testid="optimize-images">
                <h3 class="vm-ed-card__title"><VmIcon name="image" /> {{ t('editor.optimize.images', { count: report.images.total }) }}</h3>
                <p class="vm-ed-hint">{{ t('editor.optimize.imagesSummary', { missing: report.images.missingAlt, largest: kb(report.images.largestBytes) }) }}</p>
                <ul class="vm-ed-issues">
                    <li v-for="(image, index) in report.images.items.filter((item) => item.missingAlt)" :key="index">
                        <button type="button" @click="emit('focus', image.path)">{{ t('editor.optimize.missingAlt') }}</button>
                    </li>
                </ul>
            </article>

            <article class="vm-ed-card" :class="tone(report.weight.clipped, weightRatio > 80)" data-testid="optimize-weight">
                <h3 class="vm-ed-card__title"><VmIcon :name="report.weight.clipped ? 'warning' : 'check'" /> {{ t('editor.optimize.weight', { size: kb(report.weight.bytes), limit: kb(report.weight.limit) }) }}</h3>
                <p class="vm-ed-hint">{{ report.weight.clipped ? t('editor.optimize.clipped') : t('editor.optimize.clipHint') }}</p>
                <div class="vm-ed-meter" :class="{ 'vm-ed-meter--bad': report.weight.clipped, 'vm-ed-meter--warn': !report.weight.clipped && weightRatio > 80 }" role="meter" :aria-valuenow="weightRatio" aria-valuemin="0" aria-valuemax="100">
                    <span :style="{ inlineSize: `${weightRatio}%` }" />
                </div>
            </article>

            <article class="vm-ed-card" :class="tone(report.spam.warns, report.spam.score > 0)" data-testid="optimize-spam">
                <h3 class="vm-ed-card__title"><VmIcon name="shield" /> {{ t('editor.optimize.spam', { score: format.number(report.spam.score), max: 10 }) }}</h3>
                <p class="vm-ed-hint">{{ report.spam.findings.length ? t('editor.optimize.spamFindings') : t('editor.optimize.spamClean') }}</p>
                <div class="vm-ed-meter" :class="{ 'vm-ed-meter--bad': report.spam.warns, 'vm-ed-meter--warn': !report.spam.warns && report.spam.score > 0 }" role="meter" :aria-valuenow="spamRatio" aria-valuemin="0" aria-valuemax="100">
                    <span :style="{ inlineSize: `${spamRatio}%` }" />
                </div>
                <ul class="vm-ed-issues">
                    <li v-for="finding in report.spam.findings" :key="finding.rule">{{ rule(finding.rule, finding.advice) }} (+{{ finding.points }})</li>
                </ul>
            </article>

            <article v-if="report.compliance.required" class="vm-ed-card" :class="tone(report.compliance.missing.length > 0)" data-testid="optimize-compliance">
                <h3 class="vm-ed-card__title"><VmIcon :name="report.compliance.missing.length ? 'warning' : 'check'" /> {{ t('editor.optimize.compliance') }}</h3>
                <p class="vm-ed-hint">
                    {{
                        report.compliance.missing.length
                            ? t('editor.optimize.complianceMissing', { items: report.compliance.missing.map((item) => t(`editor.optimize.complianceItems.${item}`)).join(', ') })
                            : t('editor.optimize.complianceOk')
                    }}
                </p>
            </article>
        </template>

        <article class="vm-ed-card">
            <h3 class="vm-ed-card__title"><VmIcon name="send" /> {{ t('editor.testSend.title') }}</h3>
            <TestSendForm :uuid="uuid" :recipients="recipients" :domains="domains" :before-send="beforeSend" />
        </article>
    </div>
</template>
