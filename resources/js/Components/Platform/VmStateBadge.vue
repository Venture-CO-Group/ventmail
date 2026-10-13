<script setup lang="ts">
import { VtBadge, type VtBadgeTone } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * ÁLLAPOT-JELVÉNY a Platform-képernyőkhöz (DNS-rekord, kulcs, végpont, kézbesítés, üzenet).
 *
 * A `data-state` a GÉPI kód (a böngésző-teszt nyelvfüggetlenül erre illeszt), a felirat a
 * fordítás (`plat.state.<kód>`), ha van — különben maga a kód (pl. eseménynév).
 */
const props = defineProps<{ state: string; testid?: string }>();

const { t, te } = useI18n();

const TONES: Record<string, VtBadgeTone> = {
    verified: 'success',
    active: 'success',
    delivered: 'success',
    opened: 'info',
    clicked: 'info',
    accepted: 'accent',
    queued: 'accent',
    rotating: 'info',
    pending: 'warning',
    deferred: 'warning',
    failed: 'danger',
    rejected: 'danger',
    bounced: 'danger',
    complained: 'danger',
    revoked: 'danger',
    dead_letter: 'danger',
    disabled: 'neutral',
    optional: 'neutral',
    listed: 'danger',
    clean: 'success',
    quarantined: 'danger',
    warming: 'warning',
};

const tone = computed<VtBadgeTone>(() => TONES[props.state] ?? 'neutral');
const label = computed(() => (te(`plat.state.${props.state}`) ? t(`plat.state.${props.state}`) : props.state));
</script>

<template>
    <span class="vm-state" :data-state="state" :data-testid="testid">
        <VtBadge :tone="tone" size="sm">{{ label }}</VtBadge>
    </span>
</template>

<style scoped>
.vm-state {
    display: inline-flex;
    white-space: nowrap;
}
</style>
