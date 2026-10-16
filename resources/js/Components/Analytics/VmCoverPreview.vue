<script setup lang="ts">
import { computed } from 'vue';

/**
 * A4 BORÍTÓ-ELŐNÉZET (v1.1 · 8. modul) — a PDF-riport borítójának kicsinyített mása: brand-sáv a
 * workspace színével (vagy a Venture accent tokennel), logó, cégnév, cím, dátumtartomány, KPI-k,
 * lábléc oldalszámmal. A szín a szerver által validált `#RRGGBB` (`--vm-brand`).
 */
const props = withDefaults(
    defineProps<{
        color?: string | null;
        coverText?: 'light' | 'dark' | 'default' | null;
        company: string;
        footer: string;
        logoUrl?: string | null;
        title: string;
        subtitle: string;
        dateRange: string;
        kpis?: Array<{ label: string; value: string }>;
        testid?: string;
    }>(),
    { color: null, coverText: 'default', logoUrl: null, kpis: () => [], testid: undefined },
);

const style = computed(() => (props.color ? { '--vm-brand': props.color } : {}));
const initial = computed(() => (props.company.trim()[0] ?? 'V').toUpperCase());
</script>

<template>
    <div class="vm-a4wrap">
        <div class="vm-a4" :class="`vm-a4--${coverText ?? 'default'}`" :style="style" :data-testid="testid">
            <div class="vm-a4__band">
                <div class="vm-a4__brand">
                    <img v-if="logoUrl" :src="logoUrl" alt="" class="vm-a4__logo" />
                    <span v-else class="vm-a4__mark" aria-hidden="true">{{ initial }}</span>
                    <span class="vm-a4__company vm-truncate" data-testid="cover-company">{{ company }}</span>
                </div>
                <p class="vm-a4__title">{{ title }}<br />{{ subtitle }}</p>
                <p class="vm-a4__range">{{ dateRange }}</p>
            </div>
            <div v-if="kpis.length" class="vm-a4__kpis">
                <div v-for="kpi in kpis" :key="kpi.label"><b>{{ kpi.value }}</b><span>{{ kpi.label }}</span></div>
            </div>
            <div class="vm-a4__lines" aria-hidden="true"><i /><i /><i /></div>
            <div class="vm-a4__foot">
                <span class="vm-truncate" data-testid="cover-footer">{{ footer }}</span>
                <span>1 / 6</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.vm-a4wrap {
    display: grid;
    place-items: center;
    padding: var(--vt-space-5);
    border-radius: var(--vt-radius-lg);
    background: var(--vt-surface-2);
    min-width: 0;
}

.vm-a4 {
    --vm-cover-bg: var(--vm-brand, var(--vt-accent));
    --vm-cover-fg: var(--vt-text-on-accent);
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: calc(calc(var(--vt-space-8) * 4) * 2.4);
    aspect-ratio: 210 / 297;
    overflow: hidden;
    border-radius: var(--vt-radius-sm);
    background: var(--vt-color-neutral-0);
    color: var(--vt-color-neutral-900);
    box-shadow: var(--vt-elevation-popover);
    font-size: var(--vt-type-caption-size);
}

.vm-a4--light {
    --vm-cover-fg: var(--vt-color-neutral-0);
}

.vm-a4--dark {
    --vm-cover-fg: var(--vt-color-neutral-900);
}

.vm-a4__band {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    padding: var(--vt-space-4);
    background: var(--vm-cover-bg);
    color: var(--vm-cover-fg);
}

.vm-a4__brand {
    display: flex;
    align-items: center;
    gap: var(--vt-space-2);
    min-width: 0;
    font-weight: var(--vt-weight-semibold);
}

.vm-a4__logo {
    width: var(--vt-space-5);
    height: var(--vt-space-5);
    object-fit: contain;
    border-radius: var(--vt-radius-sm);
    background: var(--vt-color-neutral-0);
}

.vm-a4__mark {
    display: grid;
    place-items: center;
    width: var(--vt-space-5);
    height: var(--vt-space-5);
    border-radius: var(--vt-radius-sm);
    background: var(--vt-color-neutral-0);
    color: var(--vm-cover-bg);
    font-weight: var(--vt-weight-bold);
}

.vm-a4__title {
    margin: 0;
    font-family: var(--vt-font-display);
    font-size: var(--vt-type-body-m-size);
    line-height: 1.05;
    overflow-wrap: anywhere;
}

.vm-a4__range {
    margin: 0;
    opacity: 0.85;
}

.vm-a4__kpis {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--vt-space-2);
    padding: var(--vt-space-3);
}

.vm-a4__kpis div {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    padding: var(--vt-space-2);
    border: thin solid var(--vt-color-neutral-200);
    border-radius: var(--vt-radius-sm);
    min-width: 0;
}

.vm-a4__kpis span {
    color: var(--vt-color-neutral-500);
}

.vm-a4__lines {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    padding: 0 var(--vt-space-3);
}

.vm-a4__lines i {
    display: block;
    height: var(--vt-space-1);
    border-radius: var(--vt-radius-full);
    background: var(--vt-color-neutral-200);
}

.vm-a4__lines i:nth-child(2) {
    width: 75%;
}

.vm-a4__lines i:nth-child(3) {
    width: 85%;
}

.vm-a4__foot {
    display: flex;
    justify-content: space-between;
    gap: var(--vt-space-2);
    margin-top: auto;
    padding: var(--vt-space-2) var(--vt-space-3);
    border-top: thin solid var(--vt-color-neutral-100);
    color: var(--vt-color-neutral-500);
    min-width: 0;
}
</style>
