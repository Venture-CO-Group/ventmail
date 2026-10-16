<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { VtButton, VtEmptyState, VtField, VtInput, VtModal, VtPageHeader, VtSelect } from '@venture-co-group/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * CÍMKÉK (v1.1 · 5. modul): létrehozás, átnevezés, összevonás, törlés — darabszámokkal.
 *
 * A hivatkozott címke (szegmens, journey) nem törölhető: egy törölt címkére mutató
 * `not_has_tag` szabály mindenkit beválasztana. Az összevonás a szegmensek hivatkozását
 * átírja; a szerver mondja meg, ha egy journey miatt nem lehet.
 */
interface TagRow {
    uuid: string;
    name: string;
    contacts: number;
    createdAt: string | null;
}

const props = defineProps<{ tags: TagRow[] }>();

const { t } = useI18n();
const format = useFormat();

const newName = ref('');
const renaming = ref<TagRow | null>(null);
const renameValue = ref('');
const merging = ref<TagRow | null>(null);
const mergeInto = ref('');
const deleting = ref<TagRow | null>(null);

const mergeOptions = computed(() =>
    props.tags.filter((tag) => tag.uuid !== merging.value?.uuid).map((tag) => ({ value: tag.uuid, label: tag.name })),
);

function create(): void {
    if (!newName.value.trim()) return;

    router.post('/audience/tags', { name: newName.value.trim() }, { preserveScroll: true, onSuccess: () => (newName.value = '') });
}

function startRename(tag: TagRow): void {
    renaming.value = tag;
    renameValue.value = tag.name;
}

function rename(): void {
    if (!renaming.value || !renameValue.value.trim()) return;

    router.put(`/audience/tags/${renaming.value.uuid}`, { name: renameValue.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => (renaming.value = null),
    });
}

function startMerge(tag: TagRow): void {
    merging.value = tag;
    mergeInto.value = mergeOptions.value[0]?.value ?? '';
}

function merge(): void {
    if (!merging.value || !mergeInto.value) return;

    router.post(`/audience/tags/${merging.value.uuid}/merge`, { into: mergeInto.value }, {
        preserveScroll: true,
        onFinish: () => (merging.value = null),
    });
}

function remove(): void {
    if (!deleting.value) return;

    router.delete(`/audience/tags/${deleting.value.uuid}`, { preserveScroll: true, onFinish: () => (deleting.value = null) });
}
</script>

<template>
    <Head :title="t('tags.title')" />

    <AppLayout>
        <VtPageHeader :overline="t('nav.studio')" :title="t('tags.title')" :description="t('tags.lead')">
            <template #actions>
                <VtButton variant="ghost" @click="router.visit('/audience')">{{ t('segments.backToAudience') }}</VtButton>
            </template>
        </VtPageHeader>

        <VmCard>
            <VmForm @submit.prevent="create">
                <VmFormSection>
                    <VtField :label="t('tags.newName')">
                        <VtInput v-model="newName" data-testid="tag-new-name" :placeholder="t('tags.placeholder')" />
                    </VtField>
                </VmFormSection>
                <VmFormActions>
                    <VtButton type="submit" :disabled="!newName.trim()" data-testid="tag-create">
                        <VmIcon name="plus" /> {{ t('tags.create') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <VtEmptyState v-if="!tags.length" :title="t('tags.empty')" icon="inbox" data-testid="tags-empty" />

            <ul v-else class="vm-tag-list">
                <li v-for="tag in tags" :key="tag.uuid" class="vm-tag-list__row" :data-testid="`tag-row-${tag.name}`">
                    <span class="vm-tag-list__name">
                        <VmIcon name="tag" />
                        <strong class="vm-truncate" :title="tag.name">{{ tag.name }}</strong>
                    </span>
                    <span class="vm-tag-list__count" :data-testid="`tag-count-${tag.name}`">{{ t('tags.contacts', { count: format.number(tag.contacts) }) }}</span>
                    <span class="vm-tag-list__actions">
                        <VtButton variant="ghost" size="sm" :data-testid="`tag-rename-${tag.name}`" @click="startRename(tag)">{{ t('tags.rename') }}</VtButton>
                        <VtButton variant="ghost" size="sm" :disabled="tags.length < 2" :data-testid="`tag-merge-${tag.name}`" @click="startMerge(tag)">
                            {{ t('tags.merge') }}
                        </VtButton>
                        <VtButton variant="ghost" size="sm" :data-testid="`tag-delete-${tag.name}`" @click="deleting = tag">{{ t('tags.delete') }}</VtButton>
                    </span>
                </li>
            </ul>
        </VmCard>

        <VtModal :open="renaming !== null" :title="t('tags.renameTitle')" @close="renaming = null">
            <VtField :label="t('tags.newName')">
                <VtInput v-model="renameValue" data-testid="tag-rename-input" />
            </VtField>
            <template #footer>
                <VtButton variant="ghost" @click="renaming = null">{{ t('segments.cancel') }}</VtButton>
                <VtButton :disabled="!renameValue.trim()" data-testid="tag-rename-save" @click="rename">{{ t('tags.save') }}</VtButton>
            </template>
        </VtModal>

        <VtModal :open="merging !== null" :title="t('tags.mergeTitle', { name: merging?.name ?? '' })" @close="merging = null">
            <p>{{ t('tags.mergeHint') }}</p>
            <VtField :label="t('tags.mergeInto')">
                <VtSelect v-model="mergeInto" :options="mergeOptions" data-testid="tag-merge-into" />
            </VtField>
            <template #footer>
                <VtButton variant="ghost" @click="merging = null">{{ t('segments.cancel') }}</VtButton>
                <VtButton :disabled="!mergeInto" data-testid="tag-merge-confirm" @click="merge">{{ t('tags.merge') }}</VtButton>
            </template>
        </VtModal>

        <VtModal :open="deleting !== null" :title="t('tags.deleteTitle')" @close="deleting = null">
            <p>{{ t('tags.deleteConfirm', { name: deleting?.name ?? '', count: deleting?.contacts ?? 0 }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="deleting = null">{{ t('segments.cancel') }}</VtButton>
                <VtButton data-testid="tag-delete-confirm" @click="remove">{{ t('tags.delete') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-tag-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-tag-list__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--vt-space-2) var(--vt-space-4);
    padding-block: var(--vt-space-3);
    border-block-end: thin solid var(--vt-border);
}

.vm-tag-list__name {
    display: flex;
    flex: 1 1 calc(calc(var(--vt-space-8) * 4) * 1.5);
    align-items: center;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-tag-list__count {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
}

.vm-tag-list__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-2);
}
</style>
