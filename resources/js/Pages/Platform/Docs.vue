<script setup lang="ts">
import { VtBadge, VtSegmented, type VtColumn } from '@venture/ui';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import { sample, verifySample, type SampleLanguage } from '../../Components/Platform/codeSamples';
import VmCopyButton from '../../Components/Platform/VmCopyButton.vue';
import VmSplit from '../../Components/Platform/VmSplit.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * FEJLESZTŐI DOKUMENTÁCIÓ (v1.1 · 7. modul, D177).
 *
 * A referencia az OpenAPI-szerződésből jön (a szerver dolgozza fel), a kódmintákat a kliens
 * generálja a végpontból és a példa-törzsből. A bejövő routing és a címellenőrzési API
 * „hamarosan" (D15): a menüben jelezzük, részletes referenciát nem adunk — nem félkész funkció.
 */
interface Operation {
    id: string;
    method: string;
    path: string;
    tag: string;
    summary: string;
    description: string;
    scope: string | null;
    parameters: Array<{ name: string; in: string; required: boolean; type: string; description: string }>;
    body: Array<{ name: string; type: string; required: boolean; description: string }>;
    example: unknown;
    contentType: string | null;
    responses: Array<{ code: string; description: string }>;
}

const props = defineProps<{
    reference: { title: string; version: string; baseUrl: string; tags: Array<{ name: string; description: string }>; operations: Operation[] };
    webhook: { events: string[]; sample: Record<string, unknown>; toleranceSeconds: number };
    sandbox: { live: boolean; allowlistEnforced: boolean; testKeyPrefix: string };
}>();

const { t } = useI18n();

/* D15: ezek a felületek „hamarosan" — a menüben jelezve, részletes referencia nélkül. */
const SOON_PREFIXES = ['/routes', '/inbound', '/validate'];
const isSoon = (operation: Operation): boolean => SOON_PREFIXES.some((prefix) => operation.path.startsWith(prefix));

const available = computed(() => props.reference.operations.filter((operation) => !isSoon(operation)));

const groups = computed(() => {
    const map = new Map<string, Operation[]>();
    for (const operation of available.value) {
        map.set(operation.tag, [...(map.get(operation.tag) ?? []), operation]);
    }

    return [...map.entries()].map(([tag, operations]) => ({ tag, operations }));
});

const GUIDES = ['auth', 'errors', 'sandbox', 'webhooks'] as const;
type Guide = (typeof GUIDES)[number];

const selected = ref<string>('guide:auth');
const language = ref<SampleLanguage>('curl');

const languages = [
    { value: 'curl', label: 'curl' },
    { value: 'php', label: 'PHP' },
    { value: 'node', label: 'Node' },
    { value: 'python', label: 'Python' },
];

onMounted(() => {
    const hash = window.location.hash.slice(1);
    if (hash && (hash.startsWith('guide:') || available.value.some((operation) => operation.id === hash))) selected.value = hash;
});

function select(id: string): void {
    selected.value = id;
    history.replaceState(null, '', `#${id}`);
}

const operation = computed(() => available.value.find((item) => item.id === selected.value) ?? null);
const guide = computed<Guide | null>(() => (selected.value.startsWith('guide:') ? (selected.value.slice(6) as Guide) : null));

const url = computed(() => (operation.value ? `${props.reference.baseUrl}${operation.value.path}` : ''));

const code = computed(() =>
    operation.value
        ? sample(language.value, {
              method: operation.value.method,
              url: url.value,
              body: operation.value.example,
              idempotent: operation.value.parameters.some((parameter) => parameter.name === 'Idempotency-Key'),
          })
        : '',
);

const verifyCode = computed(() => verifySample(language.value));
const payloadJson = computed(() => JSON.stringify(props.webhook.sample, null, 2));

type ParamRow = { name: string; where: string; type: string; description: string };

const paramColumns = computed<VtColumn<ParamRow>[]>(() => [
    { key: 'name', label: t('plat.docs.parameter'), mono: true },
    { key: 'where', label: t('plat.docs.in') },
    { key: 'type', label: t('plat.infra.type'), mono: true },
    { key: 'description', label: t('plat.docs.description') },
]);

/* A szerződés leírásai markdown-jelölést hordoznak; itt sima szövegként jelennek meg. */
const plain = (text: string): string => text.replace(/\*\*/g, '').replace(/`/g, '');

const paramRows = computed<ParamRow[]>(() => [
    ...(operation.value?.parameters ?? []).map((parameter) => ({
        name: parameter.required ? `${parameter.name} *` : parameter.name,
        where: parameter.in,
        type: parameter.type,
        description: plain(parameter.description),
    })),
    ...(operation.value?.body ?? []).map((field) => ({
        name: field.required ? `${field.name} *` : field.name,
        where: 'body',
        type: field.type,
        description: plain(field.description),
    })),
]);

const schemaRows = computed(() => [
    { field: 'event', type: props.webhook.events.join(' | ') },
    { field: 'timestamp', type: 'integer (unix)' },
    { field: 'test', type: t('plat.docs.testField') },
    { field: 'message.id', type: 'string (uuid)' },
    { field: 'message.recipient', type: 'string' },
    { field: 'message.channel', type: 'transactional | marketing' },
    { field: 'message.tags', type: 'string[]' },
    { field: 'reason', type: 'string | null' },
    { field: 'variables', type: 'object (v:*)' },
    { field: 'delivery.code', type: 'integer | null' },
    { field: 'delivery.enhanced_code', type: 'string | null' },
    { field: 'delivery.classification', type: 'string | null' },
]);

const schemaColumns = computed<VtColumn<{ field: string; type: string }>[]>(() => [
    { key: 'field', label: t('plat.docs.field'), mono: true },
    { key: 'type', label: t('plat.infra.type'), mono: true },
]);

const methodTone = (method: string): 'success' | 'accent' | 'warning' | 'danger' | 'neutral' =>
    ({ GET: 'success', POST: 'accent', PATCH: 'warning', PUT: 'warning', DELETE: 'danger' })[method] as 'success' | 'accent' | 'warning' | 'danger' | undefined ?? 'neutral';
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('plat.eyebrow.docs')" :title="t('plat.docs.title')" :lead="t('plat.docs.lead')">
            <VtBadge tone="accent">v{{ reference.version }}</VtBadge>
        </VmPageHeader>

        <VmSplit>
            <template #side>
                <nav class="vm-docnav" :aria-label="t('plat.docs.title')" data-testid="docs-nav">
                    <p class="vm-docnav__group">{{ t('plat.docs.start') }}</p>
                    <button
                        v-for="item in GUIDES"
                        :key="item"
                        type="button"
                        class="vm-docnav__item"
                        :class="{ 'vm-docnav__item--active': selected === `guide:${item}` }"
                        :aria-current="selected === `guide:${item}` ? 'page' : undefined"
                        :data-testid="`docs-guide-${item}`"
                        @click="select(`guide:${item}`)"
                    >
                        {{ t(`plat.docs.guide.${item}`) }}
                    </button>

                    <template v-for="group in groups" :key="group.tag">
                        <p class="vm-docnav__group">{{ group.tag }}</p>
                        <button
                            v-for="item in group.operations"
                            :key="item.id"
                            type="button"
                            class="vm-docnav__item"
                            :class="{ 'vm-docnav__item--active': selected === item.id }"
                            :aria-current="selected === item.id ? 'page' : undefined"
                            :data-testid="`docs-op-${item.id}`"
                            @click="select(item.id)"
                        >
                            <span class="vm-docnav__method">{{ item.method }}</span>
                            <span class="vm-truncate">{{ item.path }}</span>
                        </button>
                    </template>

                    <p class="vm-docnav__group">{{ t('plat.docs.later') }}</p>
                    <span class="vm-docnav__item vm-docnav__item--soon" data-testid="docs-soon-inbound">
                        {{ t('nav.inbound') }} <VtBadge tone="accent-2" size="sm">{{ t('nav.soon') }}</VtBadge>
                    </span>
                    <span class="vm-docnav__item vm-docnav__item--soon" data-testid="docs-soon-validation">
                        {{ t('nav.validationApi') }} <VtBadge tone="accent-2" size="sm">{{ t('nav.soon') }}</VtBadge>
                    </span>
                </nav>
            </template>

            <!-- ÚTMUTATÓK -->
            <template v-if="guide">
                <VmCard :data-testid="`docs-guide-body-${guide}`">
                    <h2>{{ t(`plat.docs.guide.${guide}`) }}</h2>
                    <p>{{ t(`plat.docs.guideText.${guide}`) }}</p>

                    <template v-if="guide === 'auth'">
                        <pre class="vm-code" data-testid="docs-auth-sample">curl {{ reference.baseUrl }}/events \
  -H "Authorization: Bearer $VENTMAIL_API_KEY"</pre>
                        <p class="vm-muted">{{ t('plat.docs.authBasic') }}</p>
                    </template>

                    <template v-if="guide === 'errors'">
                        <pre class="vm-code">{ "error": { "code": "SUPPRESSED", "message": "…" } }</pre>
                    </template>

                    <template v-if="guide === 'sandbox'">
                        <dl class="vm-kv" data-testid="docs-sandbox">
                            <dt>{{ t('plat.infra.mode.live') }}</dt>
                            <dd>{{ sandbox.live ? t('plat.infra.on') : t('plat.infra.off') }}</dd>
                            <dt>{{ t('plat.infra.mode.allowlist') }}</dt>
                            <dd>{{ sandbox.allowlistEnforced || !sandbox.live ? t('plat.infra.on') : t('plat.infra.off') }}</dd>
                            <dt>{{ t('plat.docs.testKeys') }}</dt>
                            <dd class="vt-text-mono">{{ sandbox.testKeyPrefix }}…</dd>
                        </dl>
                        <pre class="vm-code">{ "o:testmode": true }</pre>
                    </template>

                    <template v-if="guide === 'webhooks'">
                        <div class="vm-codehead">
                            <h3>{{ t('plat.docs.payload') }}</h3>
                            <VmCopyButton :value="payloadJson" testid="docs-payload-copy" />
                        </div>
                        <pre class="vm-code" data-testid="docs-webhook-payload">{{ payloadJson }}</pre>
                        <VmTable :columns="schemaColumns" :rows="schemaRows" :caption="t('plat.docs.schema')" data-testid="docs-webhook-schema" />
                        <p class="vm-banner" role="note">{{ t('plat.docs.signatureNote', { seconds: webhook.toleranceSeconds }) }}</p>
                        <div class="vm-codehead">
                            <VtSegmented v-model="language" :options="languages" :label="t('plat.docs.language')" data-testid="docs-verify-language" />
                            <VmCopyButton :value="verifyCode" testid="docs-verify-copy" />
                        </div>
                        <pre class="vm-code" data-testid="docs-verify-code">{{ verifyCode }}</pre>
                    </template>
                </VmCard>
            </template>

            <!-- VÉGPONT -->
            <template v-else-if="operation">
                <VmCard data-testid="docs-operation">
                    <p class="vm-eyebrow">{{ operation.tag }}</p>
                    <h2>{{ operation.summary }}</h2>
                    <div class="vm-endpoint">
                        <VtBadge :tone="methodTone(operation.method)">{{ operation.method }}</VtBadge>
                        <code class="vm-truncate" data-testid="docs-url">{{ url }}</code>
                        <VmCopyButton :value="url" testid="docs-url-copy" />
                    </div>
                    <p v-if="operation.scope" class="vm-muted" data-testid="docs-scope">
                        {{ t('plat.docs.scope') }}: <code>{{ operation.scope }}</code>
                    </p>
                    <p class="vm-pre">{{ plain(operation.description) }}</p>

                    <VmTable v-if="paramRows.length" :columns="paramColumns" :rows="paramRows" :caption="t('plat.docs.parameters')" data-testid="docs-params">
                        <template #cell="{ column, value }">
                            <span v-if="column.key === 'description'" class="vm-desc">{{ value }}</span>
                            <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                        </template>
                    </VmTable>

                    <h3>{{ t('plat.docs.responses') }}</h3>
                    <ul class="vm-responses">
                        <li v-for="response in operation.responses" :key="response.code">
                            <code>{{ response.code }}</code> <span class="vm-muted">{{ plain(response.description) }}</span>
                        </li>
                    </ul>
                </VmCard>

                <VmCard>
                    <div class="vm-codehead">
                        <VtSegmented v-model="language" :options="languages" :label="t('plat.docs.language')" data-testid="docs-language" />
                        <VmCopyButton :value="code" testid="docs-code-copy" />
                    </div>
                    <pre class="vm-code" data-testid="docs-code">{{ code }}</pre>
                </VmCard>
            </template>
        </VmSplit>
    </AppLayout>
</template>

<style scoped>
.vm-docnav {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-docnav__group {
    margin: var(--vt-space-4) 0 var(--vt-space-1);
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
    letter-spacing: var(--vt-tracking-wider);
    text-transform: uppercase;
}

.vm-docnav__item {
    display: flex;
    align-items: center;
    gap: var(--vt-space-2);
    width: 100%;
    padding: var(--vt-space-2) var(--vt-space-3);
    border: none;
    border-radius: var(--vt-radius-md);
    background: transparent;
    color: var(--vt-text);
    font: inherit;
    text-align: left;
    cursor: pointer;
    min-width: 0;
}

.vm-docnav__item--active {
    background: var(--vt-bg-elevated);
}

.vm-docnav__item--soon {
    color: var(--vt-text-muted);
    cursor: default;
}

.vm-docnav__method {
    flex: 0 0 auto;
    font-family: var(--vt-font-mono);
    font-size: var(--vt-fs-caption);
    color: var(--vt-text-muted);
}

.vm-endpoint,
.vm-codehead {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
    min-width: 0;
}

.vm-endpoint {
    justify-content: flex-start;
}

.vm-endpoint > :last-child {
    margin-left: auto;
}

.vm-desc {
    display: block;
    min-width: calc(var(--vt-space-32) * 2);
    white-space: normal;
    overflow-wrap: anywhere;
}

.vm-endpoint code {
    flex: 1 1 auto;
    min-width: 0;
    font-family: var(--vt-font-mono);
}

.vm-codehead h3 {
    margin: 0;
}

.vm-eyebrow {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
    letter-spacing: var(--vt-tracking-wider);
    text-transform: uppercase;
}

.vm-muted {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-pre {
    white-space: pre-line;
}

.vm-responses {
    margin: 0;
    padding-left: var(--vt-space-4);
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
}

.vm-kv {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--vt-space-2) var(--vt-space-6);
    margin: 0;
}

.vm-kv dd {
    margin: 0;
}
</style>
