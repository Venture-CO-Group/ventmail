<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { VtBanner, VtButton, VtCheckbox, VtChip, VtEmptyState, VtField, VtInput, VtModal, VtPageHeader } from '@venture-co-group/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import TemplateThumb from '../../Components/Editor/TemplateThumb.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import '../../editor.css';
import { useFormat } from '../../composables/useFormat';

/*
 | SABLONKÖNYVTÁR (v1.1): saját sablonok, mentett blokkok és a hat indulósablon. Új sablon
 | üresen, indulósablonból vagy egy saját sablon másolataként jön létre — az indulósablon
 | maga nem módosul, a másolat a workspace sajátja.
 */
interface TemplateRow {
    uuid: string;
    name: string;
    subject: string;
    marketing: boolean;
    blockCount: number;
    version: number;
    thumb: Array<{ background: string | null; columns: number; kinds: string[] }>;
    updatedAt: string | null;
}

const props = defineProps<{
    templates: TemplateRow[];
    savedBlocks: Array<{ uuid: string; name: string; kind: 'section' | 'block'; block: { type: string } }>;
    starters: Array<{ key: string; name: string; description: string; accent: string }>;
}>();

const { t } = useI18n();
const format = useFormat();

const filter = ref<'all' | 'own' | 'blocks' | 'starters'>('all');
const search = ref('');
const createOpen = ref(false);
const source = ref<{ kind: 'blank' } | { kind: 'starter'; key: string } | { kind: 'copy'; uuid: string }>({ kind: 'blank' });
const name = ref('');
const subject = ref('');
const marketing = ref(true);
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const deleting = ref<TemplateRow | null>(null);

const needle = computed(() => search.value.trim().toLowerCase());

const own = computed(() => props.templates.filter((template) => !needle.value || `${template.name} ${template.subject}`.toLowerCase().includes(needle.value)));
const blocks = computed(() => props.savedBlocks.filter((block) => !needle.value || block.name.toLowerCase().includes(needle.value)));
const starters = computed(() => props.starters.filter((starter) => !needle.value || `${starter.name} ${starter.description}`.toLowerCase().includes(needle.value)));

const copyName = computed<string | null>(() => {
    const current = source.value;

    return current.kind === 'copy' ? (props.templates.find((template) => template.uuid === current.uuid)?.name ?? '') : null;
});

function show(section: 'own' | 'blocks' | 'starters'): boolean {
    return filter.value === 'all' || filter.value === section;
}

function openCreate(next: typeof source.value = { kind: 'blank' }): void {
    source.value = next;
    errors.value = {};
    name.value = next.kind === 'starter' ? (props.starters.find((starter) => starter.key === next.key)?.name ?? '') : '';
    subject.value = '';
    marketing.value = true;
    createOpen.value = true;
}

function create(): void {
    if (!name.value.trim()) return;

    busy.value = true;

    router.post(
        '/templates',
        {
            name: name.value.trim(),
            subject: subject.value.trim() || null,
            marketing: marketing.value,
            starter: source.value.kind === 'starter' ? source.value.key : null,
            from_template: source.value.kind === 'copy' ? source.value.uuid : null,
        },
        {
            onError: (failed) => (errors.value = failed),
            onFinish: () => (busy.value = false),
        },
    );
}

function remove(): void {
    const target = deleting.value;

    if (!target) return;

    router.delete(`/templates/${target.uuid}`, { onFinish: () => (deleting.value = null) });
}

function removeBlock(uuid: string): void {
    router.delete(`/template-blocks/${uuid}`, { preserveScroll: true });
}

function updated(value: string | null): string {
    return value ? format.dateTime(value) : '';
}

const blockIcon: Record<string, string> = {
    section: 'columns',
    heading: 'heading',
    text: 'paragraph',
    button: 'button',
    image: 'image',
    logo: 'logo',
    social: 'social',
    video: 'video',
    product: 'product',
    survey: 'survey',
    footer: 'footer',
    html: 'code',
};
</script>

<template>
    <Head :title="t('library.title')" />

    <AppLayout>
        <VtPageHeader :overline="t('nav.studio')" :title="t('library.title')" :description="t('page.templates.lead')" data-testid="library-header">
            <template #actions>
                <VtButton data-testid="template-new" @click="openCreate()"><VmIcon name="plus" /> {{ t('library.new') }}</VtButton>
            </template>
        </VtPageHeader>

        <div class="vm-lib-filters" role="group" :aria-label="t('library.filter')">
            <VtChip :selected="filter === 'all'" data-testid="filter-all" @update:selected="() => (filter = 'all')">{{ t('library.filters.all') }}</VtChip>
            <VtChip :selected="filter === 'own'" data-testid="filter-own" @update:selected="() => (filter = 'own')">{{ t('library.filters.own', { count: templates.length }) }}</VtChip>
            <VtChip :selected="filter === 'blocks'" data-testid="filter-blocks" @update:selected="() => (filter = 'blocks')">{{ t('library.filters.blocks', { count: savedBlocks.length }) }}</VtChip>
            <VtChip :selected="filter === 'starters'" data-testid="filter-starters" @update:selected="() => (filter = 'starters')">{{ t('library.filters.starters', { count: starters.length }) }}</VtChip>
            <VtInput v-model="search" class="vm-lib-search" type="search" :placeholder="t('library.search')" :aria-label="t('library.search')" data-testid="library-search" />
        </div>

        <section v-if="show('own')" class="vm-stack" data-testid="library-own">
            <h2>{{ t('library.own') }}</h2>
            <VtEmptyState v-if="!own.length" :title="t('library.ownEmpty')" icon="inbox" data-testid="templates-empty" />
            <div v-else class="vm-lib-grid">
                <article v-for="template in own" :key="template.uuid" class="vm-lib-card" :data-testid="`template-card-${template.uuid}`">
                    <Link :href="`/templates/${template.uuid}`" class="vm-stack" :data-testid="`edit-${template.uuid}`" :aria-label="t('library.edit', { name: template.name })">
                        <TemplateThumb :rows="template.thumb" />
                    </Link>
                    <div class="vm-lib-card__body">
                        <Link :href="`/templates/${template.uuid}`" class="vm-truncate vm-truncate--fill"><strong>{{ template.name }}</strong></Link>
                        <small>
                            {{ template.marketing ? t('library.marketing') : t('library.transactional') }} · v{{ template.version || 1 }} · {{ updated(template.updatedAt) }}
                        </small>
                        <div class="vm-cluster">
                            <VtButton size="sm" variant="ghost" :data-testid="`copy-${template.uuid}`" @click="openCreate({ kind: 'copy', uuid: template.uuid })">{{ t('library.duplicate') }}</VtButton>
                            <VtButton size="sm" variant="ghost" :data-testid="`delete-${template.uuid}`" @click="deleting = template">{{ t('library.delete') }}</VtButton>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section v-if="show('blocks')" class="vm-stack" data-testid="library-blocks">
            <h2>{{ t('library.blocks') }}</h2>
            <p v-if="!blocks.length">{{ t('library.blocksEmpty') }}</p>
            <div v-else class="vm-lib-blocks">
                <div v-for="block in blocks" :key="block.uuid" class="vm-lib-block" :data-testid="`saved-block-${block.uuid}`">
                    <span class="vm-lib-block__icon"><VmIcon :name="blockIcon[block.kind === 'section' ? 'section' : block.block.type] ?? 'layout'" /></span>
                    <span class="vm-stack">
                        <strong class="vm-truncate">{{ block.name }}</strong>
                        <small>{{ block.kind === 'section' ? t('library.savedSection') : t('library.savedBlock') }}</small>
                    </span>
                    <button type="button" class="vm-ed-iconbtn" :aria-label="t('library.delete')" :title="t('library.delete')" @click="removeBlock(block.uuid)">
                        <VmIcon name="trash" />
                    </button>
                </div>
            </div>
        </section>

        <section v-if="show('starters')" class="vm-stack" data-testid="library-starters">
            <h2>{{ t('library.starters') }} <small class="vm-ed-hint">{{ t('library.startersHint') }}</small></h2>
            <div class="vm-lib-grid">
                <button
                    v-for="starter in starters"
                    :key="starter.key"
                    type="button"
                    class="vm-lib-card"
                    :data-testid="`starter-${starter.key}`"
                    @click="openCreate({ kind: 'starter', key: starter.key })"
                >
                    <TemplateThumb :accent="starter.accent" />
                    <span class="vm-lib-card__body">
                        <strong>{{ starter.name }}</strong>
                        <small>{{ starter.description }}</small>
                    </span>
                </button>
            </div>
        </section>

        <VtModal :open="createOpen" :title="t('library.create.title')" @update:open="createOpen = $event" @close="createOpen = false">
            <VmForm @submit.prevent="create">
                <VmFormSection>
                    <div class="vm-stack">
                        <span>{{ t('library.create.source') }}</span>
                        <div class="vm-lib-filters" role="group" :aria-label="t('library.create.source')">
                            <VtChip :selected="source.kind === 'blank'" data-testid="create-blank" @update:selected="() => (source = { kind: 'blank' })">{{ t('library.create.blank') }}</VtChip>
                            <VtChip
                                v-for="starter in props.starters"
                                :key="starter.key"
                                :selected="source.kind === 'starter' && source.key === starter.key"
                                :data-testid="`create-starter-${starter.key}`"
                                @update:selected="() => (source = { kind: 'starter', key: starter.key })"
                            >
                                {{ starter.name }}
                            </VtChip>
                        </div>
                        <small v-if="copyName !== null" class="vm-ed-hint">{{ t('library.create.copyOf', { name: copyName }) }}</small>
                    </div>
                    <VtField :label="t('library.create.name')" :error="errors.name">
                        <VtInput v-model="name" data-testid="template-name" />
                    </VtField>
                    <VtField :label="t('library.create.subject')" :hint="t('library.create.subjectHint')" :error="errors.subject">
                        <VtInput v-model="subject" data-testid="template-subject" />
                    </VtField>
                    <VtCheckbox v-if="source.kind !== 'copy'" v-model="marketing" data-testid="template-marketing">{{ t('library.create.marketing') }}</VtCheckbox>
                    <VtBanner v-if="errors.blocks" tone="danger" role="status">{{ errors.blocks }}</VtBanner>
                </VmFormSection>
                <VmFormActions>
                    <VtButton variant="ghost" @click="createOpen = false">{{ t('editor.common.cancel') }}</VtButton>
                    <VtButton type="submit" :loading="busy" :disabled="!name.trim()" data-testid="template-submit">{{ t('library.create.submit') }}</VtButton>
                </VmFormActions>
            </VmForm>
        </VtModal>

        <VtModal :open="deleting !== null" :title="t('library.deleteTitle')" @update:open="!$event && (deleting = null)" @close="deleting = null">
            <p>{{ t('library.deleteConfirm', { name: deleting?.name ?? '' }) }}</p>
            <VmFormActions>
                <VtButton variant="ghost" @click="deleting = null">{{ t('editor.common.cancel') }}</VtButton>
                <VtButton data-testid="confirm-delete" @click="remove">{{ t('library.delete') }}</VtButton>
            </VmFormActions>
        </VtModal>
    </AppLayout>
</template>
