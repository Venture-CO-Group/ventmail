<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/** SABLONOK listája. A szerkesztés a block-tree-n megy, nem HTML-en. */
const props = defineProps<{
    templates: Array<{ uuid: string; name: string; subject: string; marketing: boolean; blockCount: number; updatedAt: string | null }>;
    schema: Record<string, unknown>;
}>();

const { t } = useI18n();

const name = ref('');
const subject = ref('');

type Row = { name: string; subject: string; kind: string; blocks: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('templates.name') },
    { key: 'subject', label: t('templates.subject') },
    { key: 'kind', label: t('templates.kind') },
    { key: 'blocks', label: t('templates.blocks') },
];

const rows = computed<Row[]>(() =>
    props.templates.map((template) => ({
        name: template.name,
        subject: template.subject,
        // A MARKETING sablonnak leiratkozó blokk KELL (61. kritérium) — a típus ezért nem
        // kozmetika.
        kind: template.marketing ? t('templates.marketing') : t('templates.transactional'),
        blocks: String(template.blockCount),
    })),
);

function create(): void {
    if (!name.value.trim() || !subject.value.trim()) return;

    router.post('/templates', { name: name.value.trim(), subject: subject.value.trim(), marketing: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('templates.title') }}</h1>

        <VtCard>
            <h2>{{ t('templates.new') }}</h2>

            <VtField :label="t('templates.name')">
                <VtInput v-model="name" data-testid="template-name" placeholder="Havi hírlevél" />
            </VtField>

            <VtField :label="t('templates.subject')">
                <VtInput v-model="subject" data-testid="template-subject" placeholder="Újdonságok" />
            </VtField>

            <VtButton :disabled="!name.trim() || !subject.trim()" data-testid="template-submit" @click="create">
                {{ t('templates.create') }}
            </VtButton>
        </VtCard>

        <VtCard>
            <p v-if="!rows.length" data-testid="templates-empty">{{ t('templates.empty') }}</p>
            <VtTable v-else :columns="columns" :rows="rows" :caption="t('templates.title')" data-testid="templates-table" />

            <div v-for="template in templates" :key="template.uuid" class="vm-cluster">
                <Link :href="`/templates/${template.uuid}`" :data-testid="`edit-${template.uuid}`">
                    {{ t('templates.edit') }}: {{ template.name }}
                </Link>
            </div>
        </VtCard>
    </AppLayout>
</template>
