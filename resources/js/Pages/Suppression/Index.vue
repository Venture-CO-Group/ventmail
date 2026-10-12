<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtSelect, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * TILTÓLISTA — a PreSendGate 2. kapujának a tartalma.
 *
 * A panasz- és leiratkozás-alapú sorokon NINCS feloldó gomb: az az ÉRINTETT akarata, és
 * a tenant nem törölheti, hogy újra küldhessen. A gomb hiánya itt magyarázat is.
 */
interface SuppressionRow {
    uuid: string;
    email: string;
    reason: string;
    appliesTo: string;
    source: string;
    expiresAt: string | null;
    createdAt: string | null;
    revocable: boolean;
}

const props = defineProps<{
    search: string;
    suppressions: { items: SuppressionRow[]; nextCursor: string | null };
    reasons: string[];
    scopes: string[];
}>();

const { t } = useI18n();

const search = ref(props.search);
const email = ref('');
const reason = ref('manual');
const appliesTo = ref('all');

type Row = { email: string; reason: string; scope: string; source: string; created: string };

const columns: VtColumn<Row>[] = [
    { key: 'email', label: t('suppressions.email'), mono: true },
    { key: 'reason', label: t('suppressions.reason') },
    { key: 'scope', label: t('suppressions.scope') },
    { key: 'source', label: t('suppressions.source') },
    { key: 'created', label: t('suppressions.created') },
];

const rows = computed<Row[]>(() =>
    props.suppressions.items.map((item) => ({
        email: item.email,
        reason: item.reason,
        scope: item.appliesTo,
        source: item.source,
        created: item.createdAt ? new Date(item.createdAt).toLocaleString() : '—',
    })),
);

const reasonOptions = computed(() => props.reasons.map((value) => ({ value, label: value })));
const scopeOptions = computed(() => props.scopes.map((value) => ({ value, label: value })));

function applySearch(): void {
    router.get('/suppressions', { search: search.value }, { preserveState: true, preserveScroll: true });
}

function add(): void {
    if (!email.value.trim()) return;

    router.post(
        '/suppressions',
        { email: email.value.trim(), reason: reason.value, appliesTo: appliesTo.value },
        { preserveScroll: true, onSuccess: () => (email.value = '') },
    );
}

function remove(address: string): void {
    router.delete('/suppressions', { data: { email: address }, preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('suppressions.title') }}</h1>

        <p class="vm-banner" role="status">{{ t('suppressions.note') }}</p>

        <VmCard>
            <VmForm data-testid="suppression-filters" @submit.prevent="applySearch">
                <VmFormSection>
                    <VtField :label="t('suppressions.search')">
                        <VtInput v-model="search" data-testid="suppression-search" placeholder="pelda.hu" />
                    </VtField>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" data-testid="suppression-search-submit">
                        {{ t('suppressions.searchAction') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <h2>{{ t('suppressions.add') }}</h2>

            <VmForm @submit.prevent="add">
                <VmFormSection>
                    <VtField :label="t('suppressions.email')">
                        <VtInput v-model="email" data-testid="suppression-email" placeholder="cimzett@pelda.hu" />
                    </VtField>

                    <VmFormRow>
                        <VtField :label="t('suppressions.reason')">
                            <VtSelect v-model="reason" :options="reasonOptions" data-testid="suppression-reason" />
                        </VtField>

                        <VtField :label="t('suppressions.scope')" :hint="t('suppressions.scopeHint')">
                            <VtSelect v-model="appliesTo" :options="scopeOptions" data-testid="suppression-scope" />
                        </VtField>
                    </VmFormRow>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="!email.trim()" data-testid="suppression-submit">
                        {{ t('suppressions.addAction') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <p v-if="!rows.length" data-testid="suppressions-empty">{{ t('suppressions.empty') }}</p>
            <VmTable
                v-else
                :columns="columns"
                :rows="rows"
                :caption="t('suppressions.title')"
                data-testid="suppressions-table"
            />

            <div v-for="item in suppressions.items" :key="item.uuid" class="vm-cluster">
                <span class="vt-text-mono vm-truncate vm-truncate--fill" :title="item.email">{{ item.email }}</span>
                <VtButton
                    v-if="item.revocable"
                    variant="ghost"
                    :data-testid="`remove-${item.email}`"
                    @click="remove(item.email)"
                >
                    {{ t('suppressions.remove') }}
                </VtButton>
                <!-- A GOMB HIÁNYA MAGYARÁZATTAL jár: enélkül a felhasználó azt hinné, elromlott. -->
                <span v-else :data-testid="`locked-${item.email}`">{{ t('suppressions.irrevocableShort') }}</span>
            </div>
        </VmCard>
    </AppLayout>
</template>
