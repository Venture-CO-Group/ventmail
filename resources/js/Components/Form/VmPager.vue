<script setup lang="ts">
import { VtButton } from '@venture/ui';
import { useI18n } from 'vue-i18n';
import { useFormat } from '../../composables/useFormat';

/**
 * LAPOZÓ (v1.1 · D284) — „2 / 5 · 43 találat", előző / következő. A szerveroldali lapozás
 * (`App\Support\Paged`) alakját kapja; a lapváltást a hívó végzi (`change`).
 *
 * JELENTÉS: hiányzó @venture/ui komponens: lapozó (pagination).
 */
const props = defineProps<{ page: number; last: number; total: number; testid: string; busy?: boolean }>();
const emit = defineEmits<{ change: [page: number] }>();

const { t } = useI18n();
const format = useFormat();

const go = (page: number): void => {
    if (page >= 1 && page <= props.last && page !== props.page) emit('change', page);
};
</script>

<template>
    <nav class="vm-pager" :aria-label="t('pager.label')" :data-testid="testid">
        <span class="vm-pager__info" :data-testid="`${testid}-info`">
            {{ t('pager.info', { page: format.number(page), last: format.number(Math.max(1, last)), total: format.number(total) }) }}
        </span>
        <span v-if="last > 1" class="vm-pager__buttons">
            <VtButton variant="ghost" size="sm" :disabled="busy || page <= 1" :aria-label="t('pager.prev')" :data-testid="`${testid}-prev`" @click="go(page - 1)">‹</VtButton>
            <VtButton variant="ghost" size="sm" :disabled="busy || page >= last" :aria-label="t('pager.next')" :data-testid="`${testid}-next`" @click="go(page + 1)">›</VtButton>
        </span>
    </nav>
</template>

<style scoped>
.vm-pager {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-2) var(--vt-space-4);
}

.vm-pager__info {
    color: var(--vt-text-muted);
}

.vm-pager__buttons {
    display: inline-flex;
    gap: var(--vt-space-2);
}
</style>
