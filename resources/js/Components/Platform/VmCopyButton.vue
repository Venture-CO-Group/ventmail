<script setup lang="ts">
import { VtButton } from '@venture/ui';
import { onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../Shell/VmIcon.vue';

/**
 * MÁSOLÁS GOMB (v1.1 · 7. modul) — DNS-rekord, kulcs, kódminta egy kattintással.
 *
 * A visszajelzés `aria-live`: a képernyőolvasó is hallja, hogy a vágólapra került. Ha a
 * Clipboard API nem elérhető (nem biztonságos kontextus), a gomb a rejtett szövegmező
 * kijelölésével másol — a felhasználó így sem marad eredmény nélkül.
 *
 * JELENTÉS: hiányzó @venture/ui komponens: másolás gomb (copy-to-clipboard).
 */
const props = withDefaults(defineProps<{ value: string; label?: string; testid?: string; size?: 'sm' | 'md'; iconOnly?: boolean }>(), {
    iconOnly: false,
    label: undefined,
    testid: undefined,
    size: 'sm',
});

const { t } = useI18n();
const copied = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
    } catch {
        const area = document.createElement('textarea');
        area.value = props.value;
        area.setAttribute('readonly', '');
        area.className = 'vm-visually-hidden';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }

    copied.value = true;
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => (copied.value = false), 2000);
}

onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
</script>

<template>
    <VtButton
        class="vm-copy"
        variant="ghost"
        :size="size"
        :data-testid="testid"
        :data-copied="copied ? 'true' : undefined"
        :aria-label="label ?? t('plat.copy')"
        :title="label ?? t('plat.copy')"
        @click="copy"
    >
        <VmIcon :name="copied ? 'check' : 'copy'" />
        <span v-if="!iconOnly">{{ copied ? t('plat.copied') : t('plat.copy') }}</span>
        <span v-else class="vm-visually-hidden" aria-live="polite">{{ copied ? t('plat.copied') : '' }}</span>
    </VtButton>
</template>

<style scoped>
.vm-copy {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: var(--vt-space-2);
}
</style>
