<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { VtButton, VtEmptyState, VtModal, VtPageHeader } from '@venture-co-group/ui';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * SZEGMENSEK (v1.1 · 5. modul): mentett szegmensek, becsült mérettel és használattal.
 * A szegmens a kampány és a journey UGYANAZON motorján értékelődik ki.
 */
interface SegmentRow {
    uuid: string;
    name: string;
    description: string | null;
    estimatedSize: number | null;
    estimatedAt: string | null;
    groups: number;
    campaigns: number;
}

defineProps<{ segments: SegmentRow[] }>();

const { t } = useI18n();
const format = useFormat();

const deleting = ref<SegmentRow | null>(null);

function remove(): void {
    if (!deleting.value) return;

    router.delete(`/audience/segments/${deleting.value.uuid}`, {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
}
</script>

<template>
    <Head :title="t('segments.title')" />

    <AppLayout>
        <VtPageHeader :overline="t('nav.studio')" :title="t('segments.title')" :description="t('page.segments.lead')" data-testid="segments-header">
            <template #actions>
                <VtButton variant="ghost" @click="router.visit('/audience')">{{ t('segments.backToAudience') }}</VtButton>
                <VtButton data-testid="segment-new" @click="router.visit('/audience/segments/new')">
                    <VmIcon name="plus" /> {{ t('segments.new') }}
                </VtButton>
            </template>
        </VtPageHeader>

        <VmCard>
            <VtEmptyState v-if="!segments.length" :title="t('segments.empty')" icon="inbox" data-testid="segments-empty" />

            <ul v-else class="vm-seg-list">
                <li v-for="segment in segments" :key="segment.uuid" class="vm-seg-list__row" :data-testid="`segment-row-${segment.uuid}`">
                    <div class="vm-seg-list__main">
                        <Link :href="`/audience/segments/${segment.uuid}`" class="vm-truncate" :title="segment.name" :data-testid="`segment-open-${segment.uuid}`">
                            <strong>{{ segment.name }}</strong>
                        </Link>
                        <small v-if="segment.description" class="vm-truncate" :title="segment.description">{{ segment.description }}</small>
                    </div>
                    <span class="vm-seg-list__meta">
                        {{ segment.estimatedSize === null ? t('segments.notEstimated') : t('segments.size', { count: format.number(segment.estimatedSize) }) }}
                    </span>
                    <span class="vm-seg-list__meta">{{ t('segments.usedBy', { count: segment.campaigns }) }}</span>
                    <VtButton variant="ghost" size="sm" :data-testid="`segment-delete-${segment.uuid}`" @click="deleting = segment">
                        {{ t('segments.delete') }}
                    </VtButton>
                </li>
            </ul>
        </VmCard>

        <VtModal :open="deleting !== null" :title="t('segments.deleteTitle')" @close="deleting = null">
            <p>{{ t('segments.deleteConfirm', { name: deleting?.name ?? '' }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="deleting = null">{{ t('segments.cancel') }}</VtButton>
                <VtButton data-testid="segment-delete-confirm" @click="remove">{{ t('segments.delete') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-seg-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-seg-list__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2) var(--vt-space-4);
    padding-block: var(--vt-space-3);
    border-block-end: thin solid var(--vt-border);
}

.vm-seg-list__main {
    display: flex;
    flex: 1 1 calc(calc(var(--vt-space-8) * 4) * 2);
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-seg-list__meta {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}
</style>
