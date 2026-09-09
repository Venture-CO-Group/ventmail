<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * AUDIT-KERESŐ (85. kritérium) — és a keresés MAGA is auditált.
 *
 * A megjelenített `context` a TÁROLT alak: az e-mail-címek helyén `[pii:email:<hash>]` áll
 * (az `AuditLogger` a beírás pillanatában cseréli), a titkok helyén `[redacted]`. A kereső
 * nem fejt vissza semmit — és nem is tudna.
 */
const props = defineProps<{
    filters: { action?: string; actor?: string; workspace_id?: number; cursor?: number };
    items: Array<{
        id: number;
        action: string;
        workspace_id: number | null;
        actor_email: string | null;
        auditable_type: string | null;
        created_at: string | null;
        context: Record<string, unknown>;
    }>;
    nextCursor: number | null;
}>();

const { t } = useI18n();

const action = ref(props.filters.action ?? '');
const actor = ref(props.filters.actor ?? '');

type Row = { time: string; action: string; actor: string; workspace: string };

const columns: VtColumn<Row>[] = [
    { key: 'time', label: t('platform.time') },
    { key: 'action', label: t('platform.action') },
    { key: 'actor', label: t('platform.actor') },
    { key: 'workspace', label: t('platform.workspaceId') },
];

const rows = computed<Row[]>(() =>
    props.items.map((item) => ({
        time: item.created_at ? new Date(item.created_at).toLocaleString() : '—',
        action: item.action,
        actor: item.actor_email ?? '—',
        // A PLATFORM-SZINTŰ sor (nincs workspace) nem „hiányzó adat": azt jelenti, hogy a
        // művelet egyetlen tenanthoz sem tartozik.
        workspace: item.workspace_id === null ? t('platform.platformWide') : String(item.workspace_id),
    })),
);

function search(): void {
    router.get('/platform/audit', { action: action.value, actor: actor.value }, { preserveState: true });
}

function more(): void {
    router.get(
        '/platform/audit',
        { action: action.value, actor: actor.value, cursor: props.nextCursor },
        { preserveState: true },
    );
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('platform.auditSearch') }}</h1>

        <VtCard>
            <form @submit.prevent="search">
                <VtField :label="t('platform.action')" :hint="t('platform.actionHint')">
                    <VtInput v-model="action" data-testid="audit-action" placeholder="platform." />
                </VtField>

                <VtField :label="t('platform.actor')">
                    <VtInput v-model="actor" data-testid="audit-actor" placeholder="admin@ventmail.app" />
                </VtField>

                <VtButton data-testid="audit-search" @click="search">{{ t('platform.search') }}</VtButton>
            </form>
        </VtCard>

        <VtCard>
            <p v-if="!rows.length" data-testid="audit-empty">{{ t('platform.noAuditRows') }}</p>
            <VtTable v-else :columns="columns" :rows="rows" :caption="t('platform.auditSearch')" data-testid="audit-table" />

            <VtButton v-if="nextCursor" data-testid="audit-more" @click="more">{{ t('platform.more') }}</VtButton>
        </VtCard>
    </AppLayout>
</template>
