<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtButton, VtField, VtInput, VtModal, VtSelect, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmCopyButton from '../../Components/Platform/VmCopyButton.vue';
import VmStateBadge from '../../Components/Platform/VmStateBadge.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

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
    submission: { host: string; ports: Array<{ port: number; tls: string }> };
}>();

const { t } = useI18n();
const format = useFormat();
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

type Row = { name: string; username: string; domain: string; channel: string; used: string; state: string; uuid: string };

const confirm = ref<{ kind: 'rotate' | 'revoke'; credential: CredentialRow } | null>(null);
const byUuid = computed(() => new Map(props.credentials.map((credential) => [credential.uuid, credential])));

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('smtp.name') },
    { key: 'username', label: t('smtp.username'), mono: true },
    { key: 'domain', label: t('smtp.domain') },
    { key: 'channel', label: t('smtp.channel') },
    { key: 'used', label: t('smtp.lastUsed') },
    { key: 'state', label: t('smtp.state') },
    { key: 'uuid', label: '' },
];

const rows = computed<Row[]>(() =>
    props.credentials.map((credential) => ({
        name: credential.name,
        username: credential.username,
        domain: credential.domain ?? t('smtp.anyDomain'),
        channel: credential.channel,
        used: credential.lastUsedAt ? format.dateTime(credential.lastUsedAt) : t('smtp.neverUsed'),
        state: credential.revokedAt ? 'revoked' : 'active',
        uuid: credential.uuid,
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

function confirmAction(): void {
    if (!confirm.value) return;

    const { kind, credential } = confirm.value;
    const done = { preserveScroll: true, onFinish: () => (confirm.value = null) };

    if (kind === 'rotate') {
        router.post(`/smtp-credentials/${credential.uuid}/rotate`, {}, done);
    } else {
        router.delete(`/smtp-credentials/${credential.uuid}`, done);
    }
}
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('plat.eyebrow.smtp')" :title="t('smtp.title')" :lead="t('plat.smtp.lead')" />

        <p class="vm-banner" role="status">{{ t('smtp.relayNote') }}</p>

        <VmCard v-if="issued" data-testid="issued-credential">
            <strong>{{ t('plat.smtp.issuedTitle') }}</strong>
            <p>{{ t('smtp.issuedOnce') }}</p>
            <div class="vm-cluster">
                <code class="vt-text-mono vm-wrap" data-testid="issued-username">{{ issued.username }}</code>
                <VmCopyButton :value="issued.username ?? ''" testid="copy-username" />
            </div>
            <div class="vm-cluster">
                <code class="vt-text-mono vm-wrap" data-testid="issued-password">{{ issued.password }}</code>
                <VmCopyButton :value="issued.password ?? ''" testid="copy-password" />
            </div>
        </VmCard>

        <VmCard data-testid="smtp-submission">
            <h2>{{ t('plat.smtp.connection') }}</h2>
            <dl class="vm-kv">
                <dt>{{ t('plat.smtp.host') }}</dt>
                <dd class="vt-text-mono">{{ submission.host }}</dd>
                <template v-for="port in submission.ports" :key="port.port">
                    <dt>{{ t('plat.smtp.port') }}</dt>
                    <dd class="vt-text-mono">{{ port.port }} · {{ port.tls }}</dd>
                </template>
            </dl>
            <p>{{ t('plat.smtp.perDomain') }}</p>
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
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('smtp.existing')" data-testid="credentials-table">
                <template #cell="{ row, column, value }">
                    <VmStateBadge v-if="column.key === 'state'" :state="String(value)" />
                    <span v-else-if="column.key === 'uuid'" v-show="row.state === 'active'" class="vm-cluster">
                        <VtButton
                            variant="ghost"
                            size="sm"
                            :data-testid="`rotate-${row.username}`"
                            @click="confirm = { kind: 'rotate', credential: byUuid.get(String(value))! }"
                        >
                            {{ t('plat.rotate') }}
                        </VtButton>
                        <VtButton
                            variant="ghost"
                            size="sm"
                            :data-testid="`revoke-${row.username}`"
                            @click="confirm = { kind: 'revoke', credential: byUuid.get(String(value))! }"
                        >
                            {{ t('smtp.revoke') }}
                        </VtButton>
                    </span>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>
        </VmCard>

        <VtModal
            :open="confirm !== null"
            :title="confirm?.kind === 'rotate' ? t('plat.rotate') : t('smtp.revoke')"
            @close="confirm = null"
        >
            <p>{{ confirm?.kind === 'rotate' ? t('plat.smtp.rotateConfirm', { name: confirm?.credential.name ?? '' }) : t('plat.smtp.revokeConfirm', { name: confirm?.credential.name ?? '' }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="confirm = null">{{ t('plat.cancel') }}</VtButton>
                <VtButton data-testid="smtp-confirm" @click="confirmAction">{{ t('plat.confirm') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-kv {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--vt-space-2) var(--vt-space-4);
    margin: 0;
}

.vm-kv dt {
    color: var(--vt-text-muted);
}

.vm-kv dd {
    margin: 0;
    overflow-wrap: anywhere;
}
</style>
