<script setup lang="ts">
import VmCard from '../Form/VmCard.vue';
import VmCopyButton from './VmCopyButton.vue';

/**
 * EGYSZER LÁTHATÓ TITOK (v1.1 · 7. modul): API-kulcs, SMTP-jelszó, webhook-titok.
 *
 * Az érték a FLASH-ből jön (a hívó adja), nem oldal-propból — a props a HTML-be
 * szerializálódna, és a böngésző history-jában is ott maradna. Újratöltés után eltűnik.
 */
defineProps<{ title: string; hint: string; value: string; testid?: string; extra?: string }>();
</script>

<template>
    <VmCard :data-testid="testid">
        <div class="vm-secret" role="status">
            <strong>{{ title }}</strong>
            <span class="vm-secret__hint">{{ hint }}</span>
            <p v-if="extra" class="vt-text-mono vm-wrap">{{ extra }}</p>
            <div class="vm-secret__row">
                <code class="vm-secret__value vm-wrap" :data-testid="testid ? `${testid}-value` : undefined">{{ value }}</code>
                <VmCopyButton :value="value" :testid="testid ? `${testid}-copy` : undefined" />
            </div>
        </div>
    </VmCard>
</template>

<style scoped>
.vm-secret {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-secret__hint {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-secret__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-secret__value {
    flex: 1 1 auto;
    min-width: 0;
    font-family: var(--vt-font-mono);
    font-size: var(--vt-fs-body-sm);
    padding: var(--vt-space-2) var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background: var(--vt-bg-elevated);
}
</style>
