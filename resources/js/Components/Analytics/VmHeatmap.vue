<script setup lang="ts">
import { VtBanner } from '@venture-co-group/ui';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TopLink } from './types';
import { useFormat } from '../../composables/useFormat';

/**
 * KATTINTÁSI HŐTÉRKÉP (v1.1 · 8. modul) — a kampány sablonja a küldéskori verzióban, fölötte a
 * linkenkénti kattintás-arány (egyedi kattintó / Σ egyedi kattintó).
 *
 * A sablon TENANT-HTML: a szerver `sandbox` CSP-vel adja, mi pedig szkript NÉLKÜLI,
 * `sandbox="allow-same-origin"` iframe-be tesszük (`srcdoc`) — a dokumentum kódot nem futtathat,
 * a szülő viszont megmérheti a linkek helyét (`getBoundingClientRect`). Az overlay a SZÜLŐBEN
 * rajzolódik, nem a tenant dokumentumában. A `srcdoc` azért, mert az app minden válasza
 * `frame-ancestors 'none'`/`X-Frame-Options: DENY` alatt megy — egy `src`-s iframe a saját
 * originünkről sem tölthető (D244).
 */
const props = defineProps<{ campaignUuid: string; links: TopLink[] }>();

const { t } = useI18n();
const format = useFormat();

const frame = ref<HTMLIFrameElement | null>(null);
const html = ref<string | null>(null);
const state = ref<'loading' | 'ready' | 'empty' | 'error'>('loading');
const changed = ref(false);
const height = ref(0);
const spots = ref<Array<{ key: string; left: number; top: number; width: number; height: number; share: number; tone: string }>>([]);

const shareByKey = computed(() => new Map(props.links.map((link) => [link.key, link.share])));
const totalUnique = computed(() => props.links.reduce((sum, link) => sum + link.unique, 0));

async function load(): Promise<void> {
    state.value = 'loading';

    try {
        const response = await axios.get<string>(`/analytics/campaigns/${props.campaignUuid}/heatmap`, { responseType: 'text', headers: { Accept: 'text/html' } });
        changed.value = response.headers['x-template-changed'] === '1';

        if (response.status === 204 || !response.data) {
            state.value = 'empty';

            return;
        }

        html.value = response.data;
        state.value = 'ready';
    } catch {
        state.value = 'error';
    }
}

function tone(share: number): string {
    if (share >= 30) return 'hot';
    if (share >= 15) return 'warm';

    return 'cool';
}

function measure(): void {
    const doc = frame.value?.contentDocument;

    if (!doc?.documentElement) return;

    height.value = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight ?? 0);

    const found: typeof spots.value = [];

    doc.querySelectorAll<HTMLElement>('[data-vm-link]').forEach((element) => {
        const key = element.getAttribute('data-vm-link') ?? '';
        const share = shareByKey.value.get(key);

        if (share === undefined) return;

        const rect = element.getBoundingClientRect();

        if (rect.width === 0 && rect.height === 0) return;

        found.push({ key, left: rect.left, top: rect.top, width: rect.width, height: rect.height, share, tone: tone(share) });
    });

    spots.value = found;
}

let observer: ResizeObserver | null = null;

function onLoad(): void {
    measure();
    observer?.disconnect();
    const body = frame.value?.contentDocument?.body;

    if (body && 'ResizeObserver' in window) {
        observer = new ResizeObserver(() => measure());
        observer.observe(body);
    }
}

watch(() => props.campaignUuid, () => void load());
watch(shareByKey, () => void nextTick(measure));

onMounted(() => void load());
onBeforeUnmount(() => observer?.disconnect());

const percent = (value: number) => `${format.number(value, { maximumFractionDigits: 1 })}%`;
</script>

<template>
    <div class="vm-heat" data-testid="heatmap">
        <VtBanner v-if="changed" tone="warning" role="status" data-testid="heatmap-changed">{{ t('analytics.templateChanged') }}</VtBanner>
        <p v-if="state === 'loading'" class="vm-heat__state">…</p>
        <p v-else-if="state === 'empty' || state === 'error'" class="vm-heat__state" data-testid="heatmap-empty">{{ t('analytics.heatmapEmpty') }}</p>
        <p v-else-if="!totalUnique" class="vm-heat__state" data-testid="heatmap-noclicks">{{ t('analytics.noClicks') }}</p>

        <div v-if="state === 'ready' && html" class="vm-heat__stage">
            <div class="vm-heat__paper" :style="{ height: height ? `${height}px` : undefined }">
                <iframe
                    ref="frame"
                    class="vm-heat__frame"
                    sandbox="allow-same-origin"
                    :srcdoc="html"
                    :title="t('analytics.chart.heatmap')"
                    tabindex="-1"
                    data-testid="heatmap-frame"
                    @load="onLoad"
                />
                <div class="vm-heat__overlay" aria-hidden="true">
                    <template v-for="spot in spots" :key="spot.key + spot.top">
                        <span
                            class="vm-heat__spot"
                            :class="`vm-heat__spot--${spot.tone}`"
                            :style="{ left: `${spot.left}px`, top: `${spot.top}px`, width: `${spot.width}px`, height: `${spot.height}px` }"
                        />
                        <span class="vm-heat__pct" :style="{ left: `${spot.left + spot.width / 2}px`, top: `${spot.top + spot.height / 2}px` }" data-testid="heatmap-pct">{{ percent(spot.share) }}</span>
                    </template>
                </div>
            </div>
        </div>

        <ul class="vm-visually-hidden">
            <li v-for="link in links" :key="link.key">{{ link.url }} — {{ percent(link.share) }}</li>
        </ul>
    </div>
</template>

<style scoped>
.vm-heat {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-heat__state {
    margin: 0;
    color: var(--vt-text-secondary);
}

.vm-heat__stage {
    display: flex;
    justify-content: center;
    max-height: calc(calc(var(--vt-space-8) * 4) * 5);
    overflow: auto;
    border-radius: var(--vt-radius-lg);
    background: var(--vt-surface-2);
    padding: var(--vt-space-4);
}

.vm-heat__paper {
    position: relative;
    width: 100%;
    max-width: calc(calc(var(--vt-space-8) * 4) * 4.7);
    min-height: calc(calc(var(--vt-space-8) * 4) * 2);
    flex: none;
    background: var(--vt-color-neutral-0);
    border-radius: var(--vt-radius-md);
    overflow: hidden;
}

.vm-heat__frame {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
    pointer-events: none;
}

.vm-heat__overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.vm-heat__spot {
    position: absolute;
    border-radius: var(--vt-radius-md);
    filter: blur(var(--vt-space-2));
    opacity: 0.55;
}

.vm-heat__spot--hot {
    background: var(--vt-status-danger);
}

.vm-heat__spot--warm {
    background: var(--vt-status-warning);
}

.vm-heat__spot--cool {
    background: var(--vt-color-cyan-400);
}

.vm-heat__pct {
    position: absolute;
    transform: translate(-50%, -50%);
    padding: var(--vt-space-1) var(--vt-space-2);
    border-radius: var(--vt-radius-full);
    background: var(--vt-color-neutral-900);
    color: var(--vt-color-neutral-0);
    font-family: var(--vt-font-mono);
    font-size: var(--vt-type-caption-size);
    white-space: nowrap;
}
</style>
