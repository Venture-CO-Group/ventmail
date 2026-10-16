<script setup lang="ts">
import { VtBadge, type VtBadgeTone } from '@venture-co-group/ui';

/**
 * SZÁMLÁLÓ-CSEMPE: címke, nagy szám, magyarázat — és opcionálisan egy jelvény a döntéssel
 * („kihagy", „első marad"). Az import előnézete és eredménye használja.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: statisztika-csempe (stat tile).
 */
withDefaults(
    defineProps<{
        label: string;
        value: number | string;
        hint?: string;
        badge?: string;
        badgeTone?: VtBadgeTone;
        tone?: 'neutral' | 'success' | 'warning' | 'danger';
    }>(),
    { tone: 'neutral', badgeTone: 'neutral' },
);
</script>

<template>
    <div class="vm-stat" :class="`vm-stat--${tone}`">
        <div class="vm-stat__head">
            <span class="vm-stat__label">{{ label }}</span>
            <VtBadge v-if="badge" :tone="badgeTone">{{ badge }}</VtBadge>
        </div>
        <strong class="vm-stat__value">{{ value }}</strong>
        <p v-if="hint" class="vm-stat__hint">{{ hint }}</p>
        <slot />
    </div>
</template>

<style scoped>
.vm-stat {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    padding: var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background-color: var(--vt-surface-2);
    min-width: 0;
}

.vm-stat--warning {
    border-color: var(--vt-status-warning);
}

.vm-stat--danger {
    border-color: var(--vt-status-danger);
}

.vm-stat--success .vm-stat__value {
    color: var(--vt-status-success);
}

.vm-stat__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-2);
}

.vm-stat__label {
    font-size: var(--vt-type-body-m-size);
    color: var(--vt-text-secondary);
    overflow-wrap: anywhere;
}

.vm-stat__value {
    font-family: var(--vt-font-display);
    font-size: var(--vt-type-display-m-size);
    line-height: var(--vt-type-display-m-line-height);
    font-weight: var(--vt-weight-semibold);
}

.vm-stat__hint {
    margin: 0;
    font-size: var(--vt-type-caption-size);
    color: var(--vt-text-secondary);
    overflow-wrap: anywhere;
}
</style>
