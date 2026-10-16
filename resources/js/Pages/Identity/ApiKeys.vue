<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtButton, VtCheckbox, VtField, VtInput, VtModal, VtTextarea, type VtColumn } from '@venture-co-group/ui';
import { computed, ref, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmFieldGroup from '../../Components/Form/VmFieldGroup.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmSecretReveal from '../../Components/Platform/VmSecretReveal.vue';
import VmStateBadge from '../../Components/Platform/VmStateBadge.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useFormat } from '../../composables/useFormat';

/**
 * API-KULCSOK (v1.1 · 7. modul). A nyers kulcs EGYSZER látszik — utána már csak a prefix.
 *
 * Workspace-hez kötött kulcsok scope-okkal, csatorna-korláttal és IP-allowlisttel; a lista
 * az utolsó használatot és a visszavont kulcsokat is mutatja (a prefix a naplóban nyom).
 */
interface KeyRow {
    uuid: string;
    name: string;
    prefix: string;
    scopes: string[];
    channels: string[] | null;
    testmode: boolean;
    ip_allowlist: string[];
    last_used_at: string | null;
    created_at: string | null;
    revoked_at: string | null;
}

const props = defineProps<{
    keys: KeyRow[];
    availableScopes: string[];
    availableChannels: string[];
}>();

const { t } = useI18n();
const format = useFormat();
const page = usePage<{ flash: { secret: { kind: string; value?: string } | null }; errors: Record<string, string> }>();

const creating = ref(false);
const name = ref('');
const scopes = ref<string[]>([]);
const channels = ref<string[]>([]);
const testmode = ref(false);
const allowlist = ref('');
const submitting = ref(false);
const revoking = ref<KeyRow | null>(null);

// A KIÁLLÍTOTT KULCS a flash-ből jön, nem oldal-propból: a props a HTML-be
// szerializálódik, és a böngésző history-jában is ott maradna.
const issuedKey = computed(() => {
    const secret = page.props.flash?.secret;

    return secret?.kind === 'apiKey' ? (secret.value ?? null) : null;
});

const canSubmit = computed(() => name.value.trim().length > 0 && scopes.value.length > 0);

type Row = { name: string; prefix: string; scopes: string; allowlist: string; used: string; state: string; uuid: string };

const columns = computed<VtColumn<Row>[]>(() => [
    { key: 'name', label: t('apiKeys.name') },
    { key: 'prefix', label: t('apiKeys.prefix'), mono: true },
    { key: 'scopes', label: t('apiKeys.scopes') },
    { key: 'allowlist', label: t('plat.keys.allowlist'), mono: true },
    { key: 'used', label: t('apiKeys.lastUsed') },
    { key: 'state', label: t('plat.state.label') },
]);

const rows = computed<Row[]>(() =>
    props.keys.map((key) => ({
        name: key.testmode ? `${key.name} (test)` : key.name,
        prefix: key.prefix,
        scopes: key.scopes.join(' '),
        allowlist: key.ip_allowlist.length ? key.ip_allowlist.join(', ') : t('plat.keys.anyAddress'),
        used: key.last_used_at ? format.dateTime(key.last_used_at) : t('apiKeys.neverUsed'),
        state: key.revoked_at ? 'revoked' : 'active',
        uuid: key.uuid,
    })),
);

const byUuid = computed(() => new Map(props.keys.map((key) => [key.uuid, key])));

function toggle(list: Ref<string[]>, value: string): void {
    list.value = list.value.includes(value) ? list.value.filter((item) => item !== value) : [...list.value, value];
}

/*
 | A SABLON NEM AD ÁT REF-ET: a Vue a `<script setup>` ref-jeit kibontja a sablonban, tehát
 | `toggle(scopes, …)` a nyers tömböt adná át, és a hozzárendelés némán elveszne.
 */
function toggleScope(scope: string): void {
    toggle(scopes, scope);
}

function toggleChannel(channel: string): void {
    toggle(channels, channel);
}

function issue(): void {
    if (!canSubmit.value) return;

    submitting.value = true;
    router.post(
        '/api-keys',
        {
            name: name.value.trim(),
            scopes: scopes.value,
            channels: channels.value,
            testmode: testmode.value,
            ip_allowlist: allowlist.value.trim(),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                creating.value = false;
                name.value = '';
                scopes.value = [];
                channels.value = [];
                testmode.value = false;
                allowlist.value = '';
            },
            onFinish: () => (submitting.value = false),
        },
    );
}

function revoke(): void {
    if (!revoking.value) return;

    router.delete(`/api-keys/${revoking.value.uuid}`, {
        preserveScroll: true,
        onFinish: () => (revoking.value = null),
    });
}
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('plat.eyebrow.apiKeys')" :title="t('apiKeys.title')" :lead="t('plat.keys.lead')">
            <VtButton data-testid="key-new" @click="creating = true">{{ t('apiKeys.new') }}</VtButton>
        </VmPageHeader>

        <!-- EGYSZER látszik. A szöveg ezt ki is mondja: aki elveszíti, újat állít ki. -->
        <VmSecretReveal
            v-if="issuedKey"
            :title="t('plat.keys.issuedTitle')"
            :hint="t('apiKeys.issuedOnce')"
            :value="issuedKey"
            testid="issued-key"
        />

        <VmCard>
            <p v-if="!rows.length" data-testid="keys-empty">{{ t('apiKeys.empty') }}</p>
            <VmTable v-else :columns="columns" :rows="rows" :caption="t('apiKeys.existing')" data-testid="keys-table">
                <template #cell="{ row, column, value }">
                    <span v-if="column.key === 'state'" class="vm-cluster vm-nowrap">
                        <VmStateBadge :state="String(value)" :testid="`key-state-${row.prefix}`" />
                        <VtButton
                            v-if="row.state === 'active'"
                            variant="ghost"
                            size="sm"
                            :data-testid="`revoke-${row.prefix}`"
                            @click="revoking = byUuid.get(row.uuid) ?? null"
                        >
                            {{ t('apiKeys.revoke') }}
                        </VtButton>
                    </span>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>
        </VmCard>

        <div class="vm-scope-cards">
            <VmCard v-for="group in ['messages', 'read', 'suppressions']" :key="group">
                <h2 class="vm-eyebrow">{{ t(`plat.keys.scopeCard.${group}.title`) }}</h2>
                <p>{{ t(`plat.keys.scopeCard.${group}.text`) }}</p>
            </VmCard>
        </div>

        <VtModal :open="creating" :title="t('apiKeys.new')" @close="creating = false">
            <VmForm @submit.prevent="issue">
                <VmFormSection>
                    <VtField :label="t('apiKeys.name')">
                        <VtInput v-model="name" data-testid="key-name" placeholder="CI" />
                    </VtField>

                    <VmFieldGroup :legend="t('apiKeys.scopes')" data-testid="key-scopes">
                        <VtCheckbox
                            v-for="scope in availableScopes"
                            :key="scope"
                            :model-value="scopes.includes(scope)"
                            :data-testid="`scope-${scope}`"
                            @update:model-value="toggleScope(scope)"
                        >
                            {{ scope }}
                        </VtCheckbox>
                    </VmFieldGroup>

                    <VmFieldGroup :legend="t('apiKeys.channels')" :hint="t('apiKeys.channelsHint')" data-testid="key-channels">
                        <VtCheckbox
                            v-for="channel in availableChannels"
                            :key="channel"
                            :model-value="channels.includes(channel)"
                            :data-testid="`channel-${channel}`"
                            @update:model-value="toggleChannel(channel)"
                        >
                            {{ channel }}
                        </VtCheckbox>
                    </VmFieldGroup>

                    <VtField :label="t('plat.keys.allowlist')" :hint="t('plat.keys.allowlistHint')" :error="page.props.errors?.ip_allowlist">
                        <VtTextarea v-model="allowlist" :rows="3" data-testid="key-allowlist" placeholder="203.0.113.0/24" />
                    </VtField>

                    <VtCheckbox v-model="testmode" data-testid="key-testmode">
                        {{ t('apiKeys.testmode') }}
                    </VtCheckbox>
                </VmFormSection>

                <VmFormActions>
                    <VtButton variant="ghost" @click="creating = false">{{ t('plat.cancel') }}</VtButton>
                    <VtButton type="submit" :disabled="!canSubmit || submitting" data-testid="key-submit">
                        {{ t('apiKeys.issue') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VtModal>

        <VtModal :open="revoking !== null" :title="t('apiKeys.revoke')" @close="revoking = null">
            <p>{{ t('plat.keys.revokeConfirm', { name: revoking?.name ?? '', prefix: revoking?.prefix ?? '' }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="revoking = null">{{ t('plat.cancel') }}</VtButton>
                <VtButton data-testid="revoke-confirm" @click="revoke">{{ t('apiKeys.revoke') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-scope-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(calc(var(--vt-space-8) * 4) * 2)), 1fr));
    gap: var(--vt-space-4);
}

.vm-nowrap {
    flex-wrap: nowrap;
}

.vm-eyebrow {
    margin: 0;
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
}
</style>
