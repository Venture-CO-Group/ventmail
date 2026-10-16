<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBanner, VtButton, VtEmptyState, VtField, VtInput, VtModal, VtPageHeader, VtSelect, VtTabs, VtTextarea, type VtColumn } from '@venture-co-group/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';
import { useCodes } from '../../composables/useCodes';

/**
 * TILTÓLISTÁK (v1.1 · 7. modul) — a PreSendGate 2. kapujának a tartalma, KÜLÖN listákon:
 * bounce-ok, panaszok, leiratkozások, kézi tiltások; kereséssel, kézi felvétellel és
 * indoklással auditált törléssel.
 *
 * A panasz- és leiratkozás-alapú sorokon NINCS törlő gomb: az az ÉRINTETT akarata (a szerver
 * is elutasítja — a felületen és a `/v1`-en egyaránt).
 */
interface SuppressionRow {
    uuid: string;
    email: string;
    reason: string;
    appliesTo: string;
    source: string;
    detail: string | null;
    expiresAt: string | null;
    createdAt: string | null;
    revocable: boolean;
}

const props = defineProps<{
    search: string;
    list: string;
    counts: Record<string, number>;
    suppressions: { items: SuppressionRow[]; nextCursor: string | null };
    reasons: string[];
    scopes: string[];
}>();

const { t } = useI18n();
const code = useCodes();
const format = useFormat();

const LISTS = ['bounces', 'complaints', 'unsubscribes', 'manual'] as const;

const search = ref(props.search);
const adding = ref(false);
const email = ref('');
const reason = ref('manual');
const appliesTo = ref('all');
const removing = ref<SuppressionRow | null>(null);
const justification = ref('');

const tabs = computed(() =>
    LISTS.map((id) => ({ id, label: `${t(`plat.suppressions.list.${id}`)} · ${format.number((props.counts[id] ?? 0))}` })),
);

const reasonOptions = computed(() =>
    props.reasons.filter((reason) => reason !== 'policy').map((reason) => ({ value: reason, label: code('suppressionReason', reason) })),
);
const scopeOptions = computed(() => props.scopes.map((scope) => ({ value: scope, label: code('scope', scope) })));

type Row = { email: string; reason: string; scope: string; source: string; created: string; uuid: string };

const columns = computed<VtColumn<Row>[]>(() => [
    { key: 'email', label: t('suppressions.email'), mono: true },
    { key: 'reason', label: t('suppressions.reason') },
    { key: 'scope', label: t('suppressions.scope') },
    { key: 'source', label: t('suppressions.source') },
    { key: 'created', label: t('suppressions.created') },
    { key: 'uuid', label: '' },
]);

const byUuid = computed(() => new Map(props.suppressions.items.map((item) => [item.uuid, item])));

const rows = computed<Row[]>(() =>
    props.suppressions.items.map((item) => ({
        email: item.email,
        reason: item.detail ? `${code('suppressionReason', item.reason)} · ${item.detail}` : code('suppressionReason', item.reason),
        scope: code('scope', item.appliesTo),
        source: code('source', item.source),
        created: item.createdAt ? format.date(item.createdAt) : '—',
        uuid: item.uuid,
    })),
);

function visit(list: string, extra: Record<string, string> = {}): void {
    const query: Record<string, string> = { list, ...extra };
    if (search.value.trim()) query.search = search.value.trim();

    router.get('/suppressions', query, { preserveState: true, preserveScroll: true });
}

function applySearch(): void {
    visit(props.list);
}

function add(): void {
    if (!email.value.trim()) return;

    router.post(
        '/suppressions',
        { email: email.value.trim(), reason: reason.value, appliesTo: appliesTo.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                adding.value = false;
                email.value = '';
            },
        },
    );
}

function confirmRemove(): void {
    if (!removing.value || justification.value.trim().length < 3) return;

    router.delete('/suppressions', {
        data: { email: removing.value.email, justification: justification.value.trim() },
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null;
            justification.value = '';
        },
    });
}
</script>

<template>
    <AppLayout>
        <VtPageHeader :overline="t('nav.platform')" :title="t('plat.suppressions.title')" :description="t('page.suppressions.lead')">
            <template #actions>
                <VtButton data-testid="suppression-new" @click="adding = true">{{ t('suppressions.add') }}</VtButton>
            </template>
        </VtPageHeader>

        <VmCard>
            <VtTabs :tabs="tabs" :model-value="list" :label="t('plat.suppressions.title')" data-testid="suppression-tabs" @update:model-value="visit($event)">
                <template v-for="id in LISTS" #[id] :key="id">
                    <div class="vm-stack">
                        <VmForm data-testid="suppression-filters" @submit.prevent="applySearch">
                            <div class="vm-search">
                                <VtInput v-model="search" data-testid="suppression-search" :placeholder="t('plat.suppressions.searchPlaceholder')" />
                                <VtButton type="submit" variant="secondary" data-testid="suppression-search-submit">{{ t('suppressions.searchAction') }}</VtButton>
                            </div>
                        </VmForm>

                        <VtBanner tone="info" role="note" :data-testid="`list-note-${id}`">{{ t(`plat.suppressions.note.${id}`) }}</VtBanner>

                        <VtEmptyState v-if="!rows.length" :title="t('suppressions.empty')" icon="inbox" data-testid="suppressions-empty" />
                        <VmTable v-else :columns="columns" :rows="rows" :caption="t(`plat.suppressions.list.${id}`)" data-testid="suppressions-table">
                            <template #cell="{ row, column, value }">
                                <template v-if="column.key === 'uuid'">
                                    <VtButton
                                        v-if="byUuid.get(row.uuid)?.revocable"
                                        variant="ghost"
                                        size="sm"
                                        :data-testid="`remove-${row.email}`"
                                        @click="removing = byUuid.get(row.uuid) ?? null"
                                    >
                                        {{ t('plat.suppressions.delete') }}
                                    </VtButton>
                                    <span v-else class="vm-locked" :data-testid="`locked-${row.email}`">{{ t('plat.suppressions.notDeletable') }}</span>
                                </template>
                                <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                            </template>
                        </VmTable>

                        <VtButton
                            v-if="suppressions.nextCursor"
                            variant="secondary"
                            data-testid="suppressions-more"
                            @click="visit(list, { cursor: suppressions.nextCursor })"
                        >
                            {{ t('logs.more') }}
                        </VtButton>
                    </div>
                </template>
            </VtTabs>
        </VmCard>

        <VtModal :open="adding" :title="t('suppressions.add')" @close="adding = false">
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
                    <VtButton variant="ghost" @click="adding = false">{{ t('plat.cancel') }}</VtButton>
                    <VtButton type="submit" :disabled="!email.trim()" data-testid="suppression-submit">{{ t('suppressions.addAction') }}</VtButton>
                </VmFormActions>
            </VmForm>
        </VtModal>

        <VtModal :open="removing !== null" :title="t('plat.suppressions.deleteTitle')" @close="removing = null">
            <VmForm @submit.prevent="confirmRemove">
                <VmFormSection>
                    <VtBanner tone="warning" role="status">
                        {{ t('plat.suppressions.deleteWarning', { email: removing?.email ?? '' }) }}
                    </VtBanner>
                    <VtField :label="t('plat.suppressions.justification')" required>
                        <VtTextarea v-model="justification" :rows="3" data-testid="suppression-justification" />
                    </VtField>
                </VmFormSection>
                <VmFormActions>
                    <VtButton variant="ghost" @click="removing = null">{{ t('plat.cancel') }}</VtButton>
                    <VtButton type="submit" :disabled="justification.trim().length < 3" data-testid="suppression-delete-confirm">
                        {{ t('plat.suppressions.deleteAndLog') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-search {
    display: flex;
    gap: var(--vt-space-3);
    align-items: center;
}

.vm-search > :first-child {
    flex: 1 1 auto;
    min-width: 0;
}

.vm-locked {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
    white-space: nowrap;
}
</style>
