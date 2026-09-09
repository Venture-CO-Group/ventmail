<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * KÖZÖNSÉG. A consent-állapot a kapcsolat MELLETT van, nem külön képernyőn: a marketing
 * küldés jogalapja ez, és aki nincs megerősítve, annak a 3. kapu nem enged marketinget.
 */
interface ContactRow {
    uuid: string;
    email: string;
    name: string;
    status: string;
    consent: string;
    validation: string | null;
    createdAt: string | null;
}

const props = defineProps<{
    search: string;
    lists: Array<{ uuid: string; name: string; description: string | null; fromEmail: string | null; contacts: number }>;
    segments: Array<{ uuid: string; name: string; description: string | null; estimatedSize: number | null; estimatedAt: string | null }>;
    contacts: { items: ContactRow[]; nextCursor: string | null };
}>();

const { t } = useI18n();

const search = ref(props.search);
const listName = ref('');

type Row = { email: string; name: string; status: string; consent: string; validation: string };

const columns: VtColumn<Row>[] = [
    { key: 'email', label: t('audience.email'), mono: true },
    { key: 'name', label: t('audience.name') },
    { key: 'status', label: t('audience.status') },
    { key: 'consent', label: t('audience.consent') },
    { key: 'validation', label: t('audience.validation') },
];

const rows = computed<Row[]>(() =>
    props.contacts.items.map((contact) => ({
        email: contact.email,
        name: contact.name || '—',
        status: contact.status,
        consent: contact.consent,
        validation: contact.validation ?? '—',
    })),
);

// A MEGERŐSÍTÉS NÉLKÜLI kapcsolat a kampány csendes vesztesége: kimegy a lista fele, és a
// marketinges a naplóban keresi, miért.
const unconfirmed = computed(() => props.contacts.items.filter((contact) => contact.consent !== 'confirmed').length);

function applySearch(): void {
    router.get('/audience', { search: search.value }, { preserveState: true, preserveScroll: true });
}

function createList(): void {
    if (!listName.value.trim()) return;

    router.post('/audience/lists', { name: listName.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => (listName.value = ''),
    });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('audience.title') }}</h1>

        <p v-if="unconfirmed" class="vm-banner vm-banner--warning" role="status" data-testid="audience-unconfirmed">
            {{ t('audience.unconfirmedWarning', { count: unconfirmed }) }}
        </p>

        <VtCard>
            <h2>{{ t('audience.lists') }}</h2>

            <div v-for="list in lists" :key="list.uuid" class="vm-cluster" :data-testid="`list-${list.uuid}`">
                <strong>{{ list.name }}</strong>
                <span>{{ t('audience.contactCount', { count: list.contacts }) }}</span>
                <span v-if="list.fromEmail" class="vt-text-mono">{{ list.fromEmail }}</span>
            </div>

            <p v-if="!lists.length" data-testid="lists-empty">{{ t('audience.noLists') }}</p>

            <VtField :label="t('audience.newList')">
                <VtInput v-model="listName" data-testid="list-name" placeholder="Hírlevél" />
            </VtField>

            <VtButton :disabled="!listName.trim()" data-testid="list-submit" @click="createList">
                {{ t('audience.createList') }}
            </VtButton>
        </VtCard>

        <VtCard>
            <h2>{{ t('audience.segments') }}</h2>

            <p v-if="!segments.length" data-testid="segments-empty">{{ t('audience.noSegments') }}</p>

            <div v-for="segment in segments" :key="segment.uuid" class="vm-cluster" :data-testid="`segment-${segment.uuid}`">
                <strong>{{ segment.name }}</strong>
                <!-- A MÉRET BECSÜLT, és a számítás ideje is látszik: a szegmens definíció
                     szerint él, a mérete azonnal elavul. -->
                <span>
                    {{ segment.estimatedSize === null ? t('audience.notEstimated') : segment.estimatedSize }}
                    <template v-if="segment.estimatedAt">
                        ({{ new Date(segment.estimatedAt).toLocaleDateString() }})
                    </template>
                </span>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('audience.contacts') }}</h2>

            <form @submit.prevent="applySearch">
                <VtField :label="t('audience.search')">
                    <VtInput v-model="search" data-testid="contact-search" placeholder="pelda.hu" />
                </VtField>
                <VtButton data-testid="contact-search-submit" @click="applySearch">{{ t('audience.searchAction') }}</VtButton>
            </form>

            <p v-if="!rows.length" data-testid="contacts-empty">{{ t('audience.noContacts') }}</p>
            <VtTable v-else :columns="columns" :rows="rows" :caption="t('audience.contacts')" data-testid="contacts-table" />
        </VtCard>
    </AppLayout>
</template>
