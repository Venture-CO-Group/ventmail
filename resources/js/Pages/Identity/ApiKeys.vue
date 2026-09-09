<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtButton, VtCard, VtCheckbox, VtField, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref, type Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * API-KULCSOK. A nyers kulcs EGYSZER látszik — utána már csak a prefix.
 */
interface KeyRow {
    uuid: string;
    name: string;
    prefix: string;
    scopes: string[];
    channels: string[] | null;
    testmode: boolean;
    last_used_at: string | null;
    created_at: string | null;
}

const props = defineProps<{
    keys: KeyRow[];
    availableScopes: string[];
    availableChannels: string[];
}>();

const { t } = useI18n();
const page = usePage<{ flash: { secret: { kind: string; value?: string } | null } }>();

const name = ref('');
const scopes = ref<string[]>([]);
const channels = ref<string[]>([]);
const testmode = ref(false);
const submitting = ref(false);

// A KIÁLLÍTOTT KULCS a flash-ből jön, nem oldal-propból: a props a HTML-be
// szerializálódik, és a böngésző history-jában is ott maradna.
const issuedKey = computed(() => {
    const secret = page.props.flash?.secret;

    return secret?.kind === 'apiKey' ? (secret.value ?? null) : null;
});

const canSubmit = computed(() => name.value.trim().length > 0 && scopes.value.length > 0);

type Row = { name: string; prefix: string; scopes: string; channels: string; used: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('apiKeys.name') },
    { key: 'prefix', label: t('apiKeys.prefix'), mono: true },
    { key: 'scopes', label: t('apiKeys.scopes') },
    { key: 'channels', label: t('apiKeys.channels') },
    { key: 'used', label: t('apiKeys.lastUsed') },
];

const rows = computed<Row[]>(() =>
    props.keys.map((key) => ({
        name: key.testmode ? `${key.name} (test)` : key.name,
        prefix: key.prefix,
        scopes: key.scopes.join(', '),
        // A `null` csatorna-lista MINDEN csatornát jelent — ezt ki kell írni, mert a
        // hiányzó érték a felületen „semmi"-nek látszana.
        channels: key.channels === null ? t('apiKeys.allChannels') : key.channels.join(', '),
        used: key.last_used_at ? new Date(key.last_used_at).toLocaleString() : t('apiKeys.neverUsed'),
    })),
);

function toggle(list: Ref<string[]>, value: string): void {
    list.value = list.value.includes(value) ? list.value.filter((item) => item !== value) : [...list.value, value];
}

/*
 | A SABLON NEM AD ÁT REF-ET: a Vue a `<script setup>` ref-jeit kibontja a sablonban, tehát
 | `toggle(scopes, …)` a nyers tömböt adná át, és a hozzárendelés némán elveszne. Ezért van
 | két vékony burkoló — a sablon nevet hív, nem hivatkozást ad.
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
        { name: name.value.trim(), scopes: scopes.value, channels: channels.value, testmode: testmode.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                name.value = '';
                scopes.value = [];
                channels.value = [];
                testmode.value = false;
            },
            onFinish: () => (submitting.value = false),
        },
    );
}

function revoke(uuid: string): void {
    router.delete(`/api-keys/${uuid}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('apiKeys.title') }}</h1>

        <!-- EGYSZER látszik. A szöveg ezt ki is mondja: aki elveszíti, újat állít ki. -->
        <VtCard v-if="issuedKey" data-testid="issued-key">
            <p>{{ t('apiKeys.issuedOnce') }}</p>
            <p class="vt-text-mono" data-testid="issued-key-value">{{ issuedKey }}</p>
        </VtCard>

        <VtCard>
            <h2>{{ t('apiKeys.new') }}</h2>

            <VtField :label="t('apiKeys.name')">
                <VtInput v-model="name" data-testid="key-name" placeholder="CI" />
            </VtField>

            <fieldset>
                <legend>{{ t('apiKeys.scopes') }}</legend>
                <div class="vm-cluster" data-testid="key-scopes">
                    <VtCheckbox
                        v-for="scope in availableScopes"
                        :key="scope"
                        :model-value="scopes.includes(scope)"
                        :data-testid="`scope-${scope}`"
                        @update:model-value="toggleScope(scope)"
                    >
                        {{ scope }}
                    </VtCheckbox>
                </div>
            </fieldset>

            <fieldset>
                <legend>{{ t('apiKeys.channels') }}</legend>
                <p>{{ t('apiKeys.channelsHint') }}</p>
                <div class="vm-cluster" data-testid="key-channels">
                    <VtCheckbox
                        v-for="channel in availableChannels"
                        :key="channel"
                        :model-value="channels.includes(channel)"
                        :data-testid="`channel-${channel}`"
                        @update:model-value="toggleChannel(channel)"
                    >
                        {{ channel }}
                    </VtCheckbox>
                </div>
            </fieldset>

            <VtCheckbox v-model="testmode" data-testid="key-testmode">
                {{ t('apiKeys.testmode') }}
            </VtCheckbox>

            <VtButton :disabled="!canSubmit || submitting" data-testid="key-submit" @click="issue">
                {{ t('apiKeys.issue') }}
            </VtButton>
        </VtCard>

        <VtCard>
            <h2>{{ t('apiKeys.existing') }}</h2>

            <p v-if="!rows.length" data-testid="keys-empty">{{ t('apiKeys.empty') }}</p>
            <VtTable v-else :columns="columns" :rows="rows" :caption="t('apiKeys.existing')" data-testid="keys-table" />

            <div v-for="key in keys" :key="key.uuid" class="vm-cluster">
                <span>{{ key.name }}</span>
                <VtButton variant="ghost" :data-testid="`revoke-${key.prefix}`" @click="revoke(key.uuid)">
                    {{ t('apiKeys.revoke') }}
                </VtButton>
            </div>
        </VtCard>
    </AppLayout>
</template>
