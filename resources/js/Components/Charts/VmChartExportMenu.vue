<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import VmDropdown from '../Shell/VmDropdown.vue';
import VmIcon from '../Shell/VmIcon.vue';
import type { ExportBackground } from './chartTheme';

/**
 * EXPORT-MENÜ a grafikon-kártyán (v1.1 · 8. modul) — a héj `VmDropdown`-jára (ARIA `menu`,
 * Esc, ↑/↓/Home/End). PNG átlátszó vagy sötét háttérrel; a hőtérkép-kártyán a top linkek CSV-je
 * (D230). Szerverhívás a PNG-hez nincs.
 */
const props = defineProps<{ id: string; title?: string; csvHref?: string; testid?: string }>();
const emit = defineEmits<{ export: [background: ExportBackground] }>();

const { t } = useI18n();

function choose(background: ExportBackground, close: () => void): void {
    close();
    emit('export', background);
}
</script>

<template>
    <VmDropdown
        :id="`export-${props.id}`"
        mode="menu"
        align="end"
        :label="title ? `${t('analytics.export.menu')}: ${title}` : t('analytics.export.menu')"
        :testid="testid ?? `export-${props.id}`"
        trigger-class="vm-export__trigger"
    >
        <template #trigger>
            <VmIcon name="download" />
            <span>{{ t('analytics.export.menu') }}</span>
        </template>

        <template #default="{ close }">
            <a
                v-if="csvHref"
                :href="csvHref"
                role="menuitem"
                class="vm-menuitem"
                download
                :data-testid="`export-${props.id}-csv`"
                @click="close()"
            >
                <span class="vm-menuitem__label">{{ t('analytics.export.csv') }}</span>
            </a>
            <template v-else>
                <button type="button" role="menuitem" class="vm-menuitem vm-export__item" :data-testid="`export-${props.id}-png-transparent`" @click="choose('transparent', close)">
                    <span class="vm-menuitem__label">{{ t('analytics.export.pngTransparent') }}</span>
                    <small class="vm-export__hint">{{ t('analytics.export.pngTransparentHint') }}</small>
                </button>
                <button type="button" role="menuitem" class="vm-menuitem vm-export__item" :data-testid="`export-${props.id}-png-dark`" @click="choose('dark', close)">
                    <span class="vm-menuitem__label">{{ t('analytics.export.pngDark') }}</span>
                </button>
            </template>
        </template>
    </VmDropdown>
</template>

<style scoped>
:deep(.vm-export__trigger) {
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

:deep(.vm-export__trigger:focus-visible) {
    outline: none;
    box-shadow: var(--vt-ring);
}

.vm-export__item {
    flex-direction: column;
    align-items: flex-start;
}

.vm-export__hint {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
}
</style>
