<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
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
    lists: Array<{
        uuid: string;
        name: string;
        description: string | null;
        fromEmail: string | null;
        fromName: string | null;
        contacts: number;
    }>;
    segments: Array<{ uuid: string; name: string; description: string | null; estimatedSize: number | null; estimatedAt: string | null }>;
    contacts: { items: ContactRow[]; nextCursor: string | null };
}>();

const { t } = useI18n();

const search = ref(props.search);
const listName = ref('');

/*
 | A LISTA NEVE ÉS LEÍRÁSA a CÍMZETTNEK is megjelenik (a preference center a kontakt saját
 | listáit sorolja fel), ezért szerkeszthetőnek kell lennie — soronként, hogy látható
 | legyen, melyik sort írjuk át.
 */
const editingList = ref<string | null>(null);
const listForm = ref({ name: '', description: '', fromEmail: '', fromName: '' });

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

function startListEdit(list: { uuid: string; name: string; description: string | null; fromEmail: string | null; fromName: string | null }): void {
    editingList.value = list.uuid;
    listForm.value = {
        name: list.name,
        description: list.description ?? '',
        fromEmail: list.fromEmail ?? '',
        fromName: list.fromName ?? '',
    };
}

function saveList(uuid: string): void {
    router.post(
        `/audience/lists/${uuid}`,
        {
            name: listForm.value.name.trim(),
            description: listForm.value.description.trim() || null,
            fromEmail: listForm.value.fromEmail.trim() || null,
            fromName: listForm.value.fromName.trim() || null,
        },
        { preserveScroll: true, onSuccess: () => (editingList.value = null) },
    );
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

        <VmCard>
            <h2>{{ t('audience.lists') }}</h2>

            <div v-for="list in lists" :key="list.uuid" class="vm-stack" :data-testid="`list-${list.uuid}`">
                <div class="vm-cluster">
                    <strong>{{ list.name }}</strong>
                    <span>{{ t('audience.contactCount', { count: list.contacts }) }}</span>
                    <span v-if="list.fromEmail" class="vt-text-mono">{{ list.fromEmail }}</span>

                    <VtButton variant="ghost" :data-testid="`list-edit-${list.uuid}`" @click="startListEdit(list)">
                        {{ t('audience.editList') }}
                    </VtButton>
                </div>

                <VmForm
                    v-if="editingList === list.uuid"
                    :data-testid="`list-form-${list.uuid}`"
                    @submit.prevent="saveList(list.uuid)"
                >
                    <VmFormSection>
                        <VtField :label="t('audience.listName')">
                            <VtInput v-model="listForm.name" :data-testid="`list-edit-name-${list.uuid}`" />
                        </VtField>

                        <VtField :label="t('audience.listDescription')" :hint="t('audience.listDescriptionHint')">
                            <VtInput v-model="listForm.description" :data-testid="`list-edit-description-${list.uuid}`" />
                        </VtField>

                        <VtField :label="t('audience.listFromEmail')">
                            <VtInput v-model="listForm.fromEmail" :data-testid="`list-edit-from-${list.uuid}`" />
                        </VtField>
                    </VmFormSection>

                    <VmFormActions>
                        <VtButton
                            :disabled="!listForm.name.trim()"
                            type="submit"
                            :data-testid="`list-save-${list.uuid}`"
                        >
                            {{ t('audience.saveList') }}
                        </VtButton>
                    </VmFormActions>
                </VmForm>
            </div>

            <p v-if="!lists.length" data-testid="lists-empty">{{ t('audience.noLists') }}</p>

            <VmForm @submit.prevent="createList">
                <VmFormSection>
                    <VtField :label="t('audience.newList')">
                        <VtInput v-model="listName" data-testid="list-name" placeholder="Hírlevél" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!listName.trim()" data-testid="list-submit">
                        {{ t('audience.createList') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
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
        </VmCard>

        <VmCard>
            <h2>{{ t('audience.contacts') }}</h2>

            <VmForm @submit.prevent="applySearch">
                <VmFormSection>
                    <VtField :label="t('audience.search')">
                        <VtInput v-model="search" data-testid="contact-search" placeholder="pelda.hu" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" data-testid="contact-search-submit">{{ t('audience.searchAction') }}</VtButton>
                </VmFormActions>
            </VmForm>

            <p v-if="!rows.length" data-testid="contacts-empty">{{ t('audience.noContacts') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('audience.contacts')" data-testid="contacts-table" />
        </VmCard>
    </AppLayout>
</template>
