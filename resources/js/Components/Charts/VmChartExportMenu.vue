<script setup lang="ts">
import { VtDropdown, VtDropdownItem } from '@venture/ui';
import { useI18n } from 'vue-i18n';
import { useShellMenu } from '../../composables/useMenuState';
import VmIcon from '../Shell/VmIcon.vue';
import type { ExportBackground } from './chartTheme';

/**
 * EXPORT-MENÜ a grafikon-kártyán (v1.1 · 8. modul) — a @venture/ui `VtDropdown` menü-módban
 * (ARIA `menu`, Esc, ↑/↓/Home/End, ütközés-figyelés: a lap aljához közel FELFELÉ nyílik, D315).
 * PNG átlátszó vagy sötét háttérrel; a hőtérkép-kártyán a top linkek CSV-je (D230). Szerverhívás
 * a PNG-hez nincs.
 */
const props = defineProps<{ id: string; title?: string; csvHref?: string; testid?: string }>();
const emit = defineEmits<{ export: [background: ExportBackground] }>();

const { t } = useI18n();
const menu = useShellMenu(`export-${props.id}`);
</script>

<template>
    <VtDropdown
        mode="menu"
        align="end"
        :label="title ? `${t('analytics.export.menu')}: ${title}` : t('analytics.export.menu')"
        :open="menu.open.value"
        @update:open="menu.setOpen"
    >
        <template #trigger="{ open, ...trigger }">
            <button type="button" class="vm-export__trigger" v-bind="trigger" :data-testid="testid ?? `export-${props.id}`" :data-open="open ? 'true' : undefined">
                <VmIcon name="download" />
                <span>{{ t('analytics.export.menu') }}</span>
            </button>
        </template>

        <VtDropdownItem v-if="csvHref" role="menuitem" :href="csvHref" download :data-testid="`export-${props.id}-csv`">
            {{ t('analytics.export.csv') }}
        </VtDropdownItem>
        <template v-else>
            <VtDropdownItem
                role="menuitem"
                :label="t('analytics.export.pngTransparent')"
                :description="t('analytics.export.pngTransparentHint')"
                :data-testid="`export-${props.id}-png-transparent`"
                @select="emit('export', 'transparent')"
            />
            <VtDropdownItem role="menuitem" :label="t('analytics.export.pngDark')" :data-testid="`export-${props.id}-png-dark`" @select="emit('export', 'dark')" />
        </template>
    </VtDropdown>
</template>

<style scoped>
.vm-export__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-2);
    min-height: var(--vt-control-height);
    padding: 0 var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background: var(--vt-surface);
    color: var(--vt-text);
    font-size: var(--vt-fs-body-sm);
    cursor: pointer;
    white-space: nowrap;
}

.vm-export__trigger:focus-visible {
    outline: none;
    box-shadow: var(--vt-ring);
}
</style>
