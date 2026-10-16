<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtBanner, VtButton } from '@venture-co-group/ui';
import VmCard from '../../Components/Form/VmCard.vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '../../composables/useFormat';

/**
 * A MEGHÍVOTT SAJÁT képernyője.
 *
 * SZÁNDÉKOSAN nem az `AppLayout`-ra épül: aki ide jön, jellemzően még nem tagja egyetlen
 * workspace-nek sem, tehát nincs mit navigálni — a menü, a workspace-váltó és a
 * képesség-függő menüpontok mind üresek lennének.
 *
 * A meghívó tenantról EGYETLEN adatot mutatunk: a workspace nevét. A döntéshez ennyi kell,
 * és a meghívott nem kap képet egy olyan szervezet belsejéről, amihez még nem tartozik.
 */
defineProps<{
    invitations: Array<{ uuid: string; workspace: string; roles: string[]; expiresAt: string | null }>;
    unverified: boolean;
}>();

const { t } = useI18n();
const format = useFormat();

function accept(uuid: string): void {
    router.post(`/invitations/${uuid}/accept`);
}

function decline(uuid: string): void {
    router.delete(`/invitations/${uuid}`);
}
</script>

<template>
    <main class="vm-stack" id="main">
        <h1 class="vt-num">{{ t('invitations.title') }}</h1>

        <VtBanner tone="info" role="status">{{ t('invitations.note') }}</VtBanner>

        <!-- A MEGERŐSÍTETLEN cím külön üzenet: a meghívó létezhet, de amíg a ventSSO nem
             igazolta a címet, nem váltható be — ne találgassa, miért üres a lista. -->
        <VtBanner v-if="unverified" tone="warning" data-testid="invitations-unverified">
            {{ t('invitations.unverified') }}
        </VtBanner>

        <VmCard v-for="invitation in invitations" :key="invitation.uuid" :data-testid="`invitation-${invitation.uuid}`">
            <h2>{{ invitation.workspace }}</h2>

            <p>{{ t('invitations.roles', { roles: invitation.roles.join(', ') }) }}</p>

            <p v-if="invitation.expiresAt">
                {{ t('invitations.expires', { date: format.date(invitation.expiresAt) }) }}
            </p>

            <div class="vm-cluster">
                <VtButton :data-testid="`accept-${invitation.uuid}`" @click="accept(invitation.uuid)">
                    {{ t('invitations.accept') }}
                </VtButton>

                <VtButton variant="ghost" :data-testid="`decline-${invitation.uuid}`" @click="decline(invitation.uuid)">
                    {{ t('invitations.decline') }}
                </VtButton>
            </div>
        </VmCard>

        <p v-if="!invitations.length && !unverified" data-testid="invitations-empty">{{ t('invitations.empty') }}</p>
    </main>
</template>
