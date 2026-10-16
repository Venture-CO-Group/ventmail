<script setup lang="ts">
import { VtBadge, type VtBadgeTone } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * KAMPÁNY-ÁLLAPOT jelvény (v1.1 · 5. modul): vázlat, ütemezett, küldés alatt, szüneteltetett,
 * elküldött, megszakított, sikertelen. A `data-status` a gépi kód (a böngésző-teszt nyelv-
 * függetlenül erre illeszt), a felirat a fordítás.
 */
const props = defineProps<{ status: string; testid?: string }>();

const { t, te } = useI18n();

const TONES: Record<string, VtBadgeTone> = {
    draft: 'neutral',
    scheduled: 'warning',
    sending: 'accent',
    paused: 'warning',
    sent: 'success',
    canceled: 'danger',
    failed: 'danger',
};

const tone = computed<VtBadgeTone>(() => TONES[props.status] ?? 'neutral');
const label = computed(() => (te(`campaigns.statusLabel.${props.status}`) ? t(`campaigns.statusLabel.${props.status}`) : props.status));
</script>

<template>
    <span class="vm-status" :data-status="status" :data-testid="testid">
        <VtBadge :tone="tone">{{ label }}</VtBadge>
    </span>
</template>

<style scoped>
.vm-status {
    display: inline-flex;
    white-space: nowrap;
}
</style>
