<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
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
 * SMTP-HITELESÍTŐK. A jelszó EGYSZER látszik; a lista a csatorna-korlátot és az utolsó
 * használatot mutatja — abból derül ki, hogy egy régi hitelesítő elfelejtve, de ÉLŐEN
 * ott maradt.
 */
interface CredentialRow {
    uuid: string;
    name: string;
    username: string;
    domain: string | null;
    channel: string;
    rateLimit: number;
    requireTls: boolean;
    revokedAt: string | null;
    lastUsedAt: string | null;
}

const props = defineProps<{
    credentials: CredentialRow[];
    domains: Array<{ uuid: string; name: string }>;
    channels: string[];
    defaultRateLimit: number;
}>();

const { t } = useI18n();
const page = usePage<{ flash: { secret: { kind: string; username?: string; password?: string } | null } }>();

const name = ref('');
const domain = ref('');
const channel = ref(props.channels[0] ?? 'transactional');
const submitting = ref(false);

const issued = computed(() => {
    const secret = page.props.flash?.secret;

    return secret?.kind === 'smtp' ? secret : null;
});

const domainOptions = computed(() => [
    { value: '', label: t('smtp.anyDomain') },
    ...props.domains.map((item) => ({ value: item.uuid, label: item.name })),
]);

const channelOptions = computed(() => props.channels.map((code) => ({ value: code, label: code })));

type Row = { name: string; username: string; domain: string; channel: string; used: string; state: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('smtp.name') },
    { key: 'username', label: t('smtp.username'), mono: true },
    { key: 'domain', label: t('smtp.domain') },
    { key: 'channel', label: t('smtp.channel') },
    { key: 'used', label: t('smtp.lastUsed') },
    { key: 'state', label: t('smtp.state') },
];

const rows = computed<Row[]>(() =>
    props.credentials.map((credential) => ({
        name: credential.name,
        username: credential.username,
        domain: credential.domain ?? t('smtp.anyDomain'),
        channel: credential.channel,
        used: credential.lastUsedAt ? new Date(credential.lastUsedAt).toLocaleString() : t('smtp.neverUsed'),
        state: credential.revokedAt ? t('smtp.revoked') : t('smtp.active'),
    })),
);

function issue(): void {
    if (!name.value.trim()) return;

    submitting.value = true;
    router.post(
        '/smtp-credentials',
        { name: name.value.trim(), domain: domain.value, channel: channel.value },
        {
            preserveScroll: true,
            onSuccess: () => (name.value = ''),
            onFinish: () => (submitting.value = false),
        },
    );
}

function revoke(uuid: string): void {
    router.delete(`/smtp-credentials/${uuid}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('smtp.title') }}</h1>

        <p class="vm-banner" role="status">{{ t('smtp.relayNote') }}</p>

        <VmCard v-if="issued" data-testid="issued-credential">
            <p>{{ t('smtp.issuedOnce') }}</p>
            <p class="vt-text-mono vm-wrap" data-testid="issued-username">{{ issued.username }}</p>
            <p class="vt-text-mono vm-wrap" data-testid="issued-password">{{ issued.password }}</p>
        </VmCard>

        <VmCard>
            <h2>{{ t('smtp.new') }}</h2>

            <VmForm @submit.prevent="issue">
                <VmFormSection>
                    <VtField :label="t('smtp.name')">
                        <VtInput v-model="name" data-testid="credential-name" placeholder="Legacy CRM" />
                    </VtField>

                    <VmFormRow>
                        <VtField :label="t('smtp.domain')" :hint="t('smtp.domainHint')">
                            <VtSelect v-model="domain" :options="domainOptions" data-testid="credential-domain" />
                        </VtField>

                        <VtField :label="t('smtp.channel')" :hint="t('smtp.channelHint')">
                            <VtSelect v-model="channel" :options="channelOptions" data-testid="credential-channel" />
                        </VtField>
                    </VmFormRow>

                    <p>{{ t('smtp.rateLimit', { limit: defaultRateLimit }) }}</p>
                </VmFormSection>

                <VmFormActions>
                    <VtButton type="submit" :disabled="submitting || !name.trim()" data-testid="credential-submit">
                        {{ t('smtp.issue') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VmCard>

        <VmCard>
            <h2>{{ t('smtp.existing') }}</h2>

            <p v-if="!rows.length" data-testid="credentials-empty">{{ t('smtp.empty') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('smtp.existing')" data-testid="credentials-table" />

            <div v-for="credential in credentials" :key="credential.uuid" class="vm-cluster">
                <span class="vm-truncate vm-truncate--fill" :title="credential.name">{{ credential.name }}</span>
                <VtButton
                    v-if="!credential.revokedAt"
                    variant="ghost"
                    :data-testid="`revoke-${credential.username}`"
                    @click="revoke(credential.uuid)"
                >
                    {{ t('smtp.revoke') }}
                </VtButton>
            </div>
        </VmCard>
    </AppLayout>
</template>
