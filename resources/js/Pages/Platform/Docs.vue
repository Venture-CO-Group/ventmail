<script setup lang="ts">
import { VtBadge, VtBanner, VtInput, VtNavItem, VtPageHeader, VtSegmented, type VtColumn } from '@venture-co-group/ui';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import { sample, verifySample, type SampleLanguage } from '../../Components/Platform/codeSamples';
import VmCopyButton from '../../Components/Platform/VmCopyButton.vue';
import VmSplit from '../../Components/Platform/VmSplit.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * FEJLESZTŐI DOKUMENTÁCIÓ (v1.1 · 7. modul, D177; UI-polírozás · 9. pont, D305–D307).
 *
 * KÉT forrás, mindkettő EGY fájl:
 *  - a REFERENCIA (végpontok, scope-ok, hibakódok, `o:`/`h:`/`v:` vezérlők) a
 *    `docs/openapi/ventmail-v1.yaml` szerződésből — a szerver dolgozza fel, a scope a route-ból;
 *  - a PRÓZAI útmutatók (hitelesítés, Messages API, SMTP, webhook-aláírás, hibák, rate-limit,
 *    sandbox) a `docs/developer/guides.yaml`-ból, a számok a futó kódból kitöltve.
 *
 * A kódmintákat a kliens generálja a végpontból és a példa-törzsből. A bejövő routing és a
 * címellenőrzés „hamarosan" (D15): a szerződés végpontjai jelölve látszanak (drift = 0), új
 * végpontot a felület nem talál ki.
 */
interface Operation {
    id: string;
    method: string;
    path: string;
    tag: string;
    summary: string;
    description: string;
    scope: string | null;
    soon: boolean;
    parameters: Array<{ name: string; in: string; required: boolean; type: string; description: string }>;
    body: Array<{ name: string; type: string; required: boolean; description: string }>;
    example: unknown;
    contentType: string | null;
    responses: Array<{ code: string; description: string }>;
}

interface Guide {
    key: string;
    soon: boolean;
    title: string;
    html: string;
    text: string;
}

const props = defineProps<{
    reference: {
        title: string;
        version: string;
        baseUrl: string;
        tags: Array<{ name: string; description: string }>;
        operations: Operation[];
        errorCodes: string[];
        controls: Array<{ name: string; type: string; description: string }>;
    };
    guides: Guide[];
    scopes: string[];
    webhook: { events: string[]; sample: Record<string, unknown>; toleranceSeconds: number };
    sandbox: { live: boolean; allowlistEnforced: boolean; testKeyPrefix: string };
}>();

const { t } = useI18n();

/* ---------- keresés ---------- */

const query = ref('');
const needle = computed(() => query.value.trim().toLowerCase());
const matches = (...fields: string[]): boolean => needle.value === '' || fields.some((field) => field.toLowerCase().includes(needle.value));

const guideMatches = (guide: Guide): boolean => matches(guide.title, guide.text);
const operationMatches = (operation: Operation): boolean => matches(operation.method, operation.path, operation.summary, operation.tag, operation.scope ?? '');

/* ---------- navigáció ---------- */

const mainGuides = computed(() => props.guides.filter((guide) => !guide.soon && guideMatches(guide)));
const soonGuides = computed(() => props.guides.filter((guide) => guide.soon && guideMatches(guide)));

const groups = computed(() => {
    const map = new Map<string, Operation[]>();

    for (const operation of props.reference.operations.filter((item) => !item.soon && operationMatches(item))) {
        map.set(operation.tag, [...(map.get(operation.tag) ?? []), operation]);
    }

    return [...map.entries()].map(([tag, operations]) => ({ tag, operations }));
});

const soonOperations = computed(() => props.reference.operations.filter((item) => item.soon && operationMatches(item)));

const nothingFound = computed(() => !mainGuides.value.length && !soonGuides.value.length && !groups.value.length && !soonOperations.value.length);

const selected = ref<string>('guide:auth');
const language = ref<SampleLanguage>('curl');

const languages = [
    { value: 'curl', label: 'curl' },
    { value: 'php', label: 'PHP' },
    { value: 'node', label: 'JavaScript' },
    { value: 'python', label: 'Python' },
];

onMounted(() => {
    const hash = window.location.hash.slice(1);
    if (hash && (props.guides.some((guide) => `guide:${guide.key}` === hash) || props.reference.operations.some((operation) => operation.id === hash))) selected.value = hash;
});

function select(id: string): void {
    selected.value = id;
    history.replaceState(null, '', `#${id}`);
}

/** A „hamarosan" útmutatók testid-je a régi menüpontoké (a meglévő tesztek ezt keresik). */
const guideTestid = (guide: Guide): string => (guide.soon ? `docs-soon-${guide.key}` : `docs-guide-${guide.key}`);

const operation = computed(() => props.reference.operations.find((item) => item.id === selected.value) ?? null);
const guide = computed<Guide | null>(() => (selected.value.startsWith('guide:') ? (props.guides.find((item) => `guide:${item.key}` === selected.value) ?? null) : null));

/** A „hamarosan" útmutató alatti végpontok: a bejövő a `/routes` + `/inbound`, a címellenőrzés a `/validate`. */
const guideOperations = computed(() => {
    const prefixes: Record<string, string[]> = { inbound: ['/routes', '/inbound'], validation: ['/validate'] };
    const own = prefixes[guide.value?.key ?? ''] ?? [];

    return props.reference.operations.filter((item) => own.some((prefix) => item.path.startsWith(prefix)));
});

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

const authSample = computed(() => `curl ${props.reference.baseUrl}/events \\\n  -H "Authorization: Bearer $VENTMAIL_API_KEY"`);
const verifyCode = computed(() => verifySample(language.value));
const payloadJson = computed(() => JSON.stringify(props.webhook.sample, null, 2));

/* ---------- táblák ---------- */

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

type ScopeRow = { scope: string; endpoints: string };

const scopeColumns = computed<VtColumn<ScopeRow>[]>(() => [
    { key: 'scope', label: t('plat.docs.scope'), mono: true },
    { key: 'endpoints', label: t('plat.docs.soonEndpoints') },
]);

/* A scope → végpontok párosítás a referenciából (a scope-ot a route adja). */
const scopeRows = computed<ScopeRow[]>(() =>
    props.scopes.map((scope) => {
        const endpoints = props.reference.operations.filter((item) => item.scope === scope).map((item) => `${item.method} ${item.path}`);

        return { scope, endpoints: endpoints.length ? endpoints.join(' · ') : scope === 'messages:send:marketing' ? 'POST /messages (o:channel=marketing)' : t('plat.docs.scopeNone') };
    }),
);

type ControlRow = { name: string; type: string; description: string };

const controlColumns = computed<VtColumn<ControlRow>[]>(() => [
    { key: 'name', label: t('plat.docs.parameter'), mono: true },
    { key: 'type', label: t('plat.infra.type'), mono: true },
    { key: 'description', label: t('plat.docs.description') },
]);

const controlRows = computed<ControlRow[]>(() => props.reference.controls.map((control) => ({ ...control, description: plain(control.description) })));

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
        <VtPageHeader :overline="t('nav.platform')" :title="t('plat.docs.title')" :description="t('page.docs.lead')">
            <template #actions>
                <VtBadge tone="accent">v{{ reference.version }}</VtBadge>
            </template>
        </VtPageHeader>

        <VmSplit>
            <template #side>
                <nav class="vm-docnav" :aria-label="t('plat.docs.title')" data-testid="docs-nav" :data-operation-count="reference.operations.length">
                    <VtInput v-model="query" type="search" :placeholder="t('plat.docs.search')" :aria-label="t('plat.docs.search')" data-testid="docs-search" />
                    <p v-if="nothingFound" class="vm-muted" role="status" data-testid="docs-search-empty">{{ t('plat.docs.searchEmpty') }}</p>

                    <template v-if="mainGuides.length">
                        <p class="vm-docnav__group">{{ t('plat.docs.guides') }}</p>
                        <VtNavItem
                            v-for="item in mainGuides"
                            :key="item.key"
                            :label="item.title"
                            :active="selected === `guide:${item.key}`"
                            :data-testid="guideTestid(item)"
                            @click="select(`guide:${item.key}`)"
                        />
                    </template>

                    <template v-for="group in groups" :key="group.tag">
                        <p class="vm-docnav__group">{{ group.tag }}</p>
                        <VtNavItem
                            v-for="item in group.operations"
                            :key="item.id"
                            :active="selected === item.id"
                            :data-testid="`docs-op-${item.id}`"
                            @click="select(item.id)"
                        >
                            <span class="vm-docnav__op"><span class="vm-docnav__method">{{ item.method }}</span><span class="vm-truncate">{{ item.path }}</span></span>
                        </VtNavItem>
                    </template>

                    <!-- D15: „hamarosan" — a szerződés végpontjai jelölve (drift = 0), új végpont nincs. -->
                    <template v-if="soonGuides.length || soonOperations.length">
                        <p class="vm-docnav__group">{{ t('plat.docs.later') }}</p>
                        <VtNavItem
                            v-for="item in soonGuides"
                            :key="item.key"
                            class="vm-docnav__soon"
                            :label="item.title"
                            :active="selected === `guide:${item.key}`"
                            :data-testid="guideTestid(item)"
                            @click="select(`guide:${item.key}`)"
                        >
                            <template #trailing><VtBadge tone="accent">{{ t('nav.soon') }}</VtBadge></template>
                        </VtNavItem>
                        <VtNavItem
                            v-for="item in soonOperations"
                            :key="item.id"
                            class="vm-docnav__soon"
                            :active="selected === item.id"
                            :data-testid="`docs-op-${item.id}`"
                            @click="select(item.id)"
                        >
                            <span class="vm-docnav__op"><span class="vm-docnav__method">{{ item.method }}</span><span class="vm-truncate">{{ item.path }}</span></span>
                        </VtNavItem>
                    </template>
                </nav>
            </template>

            <!-- ÚTMUTATÓK (docs/developer/guides.yaml) + a szerződésből generált kiegészítők -->
            <template v-if="guide">
                <VmCard :data-testid="`docs-guide-body-${guide.key}`">
                    <h2>
                        {{ guide.title }}
                        <VtBadge v-if="guide.soon" tone="accent">{{ t('nav.soon') }}</VtBadge>
                    </h2>
                    <!-- A törzs a szerveren készült HTML: CommonMark, NYERS HTML nélkül (DeveloperGuides). -->
                    <div class="vm-prose" data-testid="docs-guide-prose" v-html="guide.html" />

                    <template v-if="guide.key === 'auth'">
                        <div class="vm-codehead">
                            <h3>curl</h3>
                            <VmCopyButton :value="authSample" testid="docs-auth-copy" />
                        </div>
                        <pre class="vm-code" data-testid="docs-auth-sample">{{ authSample }}</pre>
                        <VmTable :columns="scopeColumns" :rows="scopeRows" :caption="t('plat.docs.scopes')" data-testid="docs-scopes">
                            <template #cell="{ column, value }">
                                <span v-if="column.key === 'endpoints'" class="vm-desc">{{ value }}</span>
                                <span v-else>{{ value }}</span>
                            </template>
                        </VmTable>
                    </template>

                    <template v-if="guide.key === 'messages'">
                        <VmTable :columns="controlColumns" :rows="controlRows" :caption="t('plat.docs.controls')" data-testid="docs-controls">
                            <template #cell="{ column, value }">
                                <span v-if="column.key === 'description'" class="vm-desc">{{ value }}</span>
                                <span v-else>{{ value }}</span>
                            </template>
                        </VmTable>
                    </template>

                    <template v-if="guide.key === 'errors'">
                        <ul class="vm-codes" :aria-label="t('plat.docs.errorCodes')" data-testid="docs-error-codes">
                            <li v-for="item in reference.errorCodes" :key="item"><code>{{ item }}</code></li>
                        </ul>
                    </template>

                    <template v-if="guide.key === 'sandbox'">
                        <dl class="vm-kv" data-testid="docs-sandbox">
                            <dt>{{ t('plat.infra.mode.live') }}</dt>
                            <dd>{{ sandbox.live ? t('plat.infra.on') : t('plat.infra.off') }}</dd>
                            <dt>{{ t('plat.infra.mode.allowlist') }}</dt>
                            <dd>{{ sandbox.allowlistEnforced || !sandbox.live ? t('plat.infra.on') : t('plat.infra.off') }}</dd>
                            <dt>{{ t('plat.docs.testKeys') }}</dt>
                            <dd class="vt-mono">{{ sandbox.testKeyPrefix }}…</dd>
                        </dl>
                    </template>

                    <template v-if="guide.key === 'webhooks'">
                        <div class="vm-codehead">
                            <h3>{{ t('plat.docs.payload') }}</h3>
                            <VmCopyButton :value="payloadJson" testid="docs-payload-copy" />
                        </div>
                        <pre class="vm-code" data-testid="docs-webhook-payload">{{ payloadJson }}</pre>
                        <VmTable :columns="schemaColumns" :rows="schemaRows" :caption="t('plat.docs.schema')" data-testid="docs-webhook-schema" />
                        <div class="vm-codehead">
                            <VtSegmented v-model="language" :options="languages" :label="t('plat.docs.language')" data-testid="docs-verify-language" />
                            <VmCopyButton :value="verifyCode" testid="docs-verify-copy" />
                        </div>
                        <pre class="vm-code" data-testid="docs-verify-code">{{ verifyCode }}</pre>
                    </template>

                    <template v-if="guideOperations.length">
                        <h3>{{ t('plat.docs.soonEndpoints') }}</h3>
                        <ul class="vm-responses" data-testid="docs-soon-operations">
                            <li v-for="item in guideOperations" :key="item.id">
                                <button type="button" class="vm-linkbtn" @click="select(item.id)">
                                    <code>{{ item.method }} {{ item.path }}</code>
                                </button>
                                <span class="vm-muted">{{ item.summary }}</span>
                            </li>
                        </ul>
                    </template>
                </VmCard>
            </template>

            <!-- VÉGPONT -->
            <template v-else-if="operation">
                <VmCard data-testid="docs-operation">
                    <p class="vm-eyebrow">{{ operation.tag }}</p>
                    <h2>{{ operation.summary }}</h2>
                    <VtBanner v-if="operation.soon" tone="warning" role="note" data-testid="docs-soon-banner">{{ t('plat.docs.soonBanner') }}</VtBanner>
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
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
}

.vm-docnav__soon {
    color: var(--vt-text-secondary);
}

.vm-docnav__op {
    display: flex;
    align-items: baseline;
    gap: var(--vt-space-2);
    min-width: 0;
}

.vm-docnav__method {
    flex: 0 0 auto;
    font-family: var(--vt-font-mono);
    font-size: var(--vt-type-caption-size);
    color: var(--vt-text-secondary);
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
    min-width: calc(calc(var(--vt-space-8) * 4) * 2);
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
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
}

.vm-muted {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-body-m-size);
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

/* A prózai útmutató (szerveren készült CommonMark HTML) — tokenekből. */
.vm-prose {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-3);
    min-width: 0;
    overflow-wrap: anywhere;
}

.vm-prose :deep(p),
.vm-prose :deep(ul),
.vm-prose :deep(ol),
.vm-prose :deep(pre),
.vm-prose :deep(table) {
    margin: 0;
}

.vm-prose :deep(ul),
.vm-prose :deep(ol) {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    padding-left: var(--vt-space-6);
}

.vm-prose :deep(code) {
    font-family: var(--vt-font-mono);
    font-size: var(--vt-type-body-m-size);
}

.vm-prose :deep(pre) {
    padding: var(--vt-space-4);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background: var(--vt-bg-base);
    overflow-x: auto;
    overflow-wrap: normal;
}

.vm-prose :deep(table) {
    display: block;
    max-inline-size: 100%;
    overflow-x: auto;
    border-collapse: collapse;
}

.vm-prose :deep(th),
.vm-prose :deep(td) {
    padding: var(--vt-space-2) var(--vt-space-3);
    border-block-end: thin solid var(--vt-border);
    text-align: start;
    vertical-align: top;
}

/* A kulcs-oszlop (beállítás / keret neve) nem törik szó közben. */
.vm-prose :deep(td:first-child) {
    overflow-wrap: normal;
    white-space: nowrap;
}

.vm-prose :deep(th) {
    color: var(--vt-text-secondary);
    font-size: var(--vt-type-caption-size);
    letter-spacing: var(--vt-type-overline-tracking);
    text-transform: uppercase;
}

.vm-codes {
    display: flex;
    flex-wrap: wrap;
    gap: var(--vt-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.vm-codes code {
    display: inline-block;
    padding: var(--vt-space-1) var(--vt-space-2);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-sm);
    font-family: var(--vt-font-mono);
    font-size: var(--vt-type-caption-size);
}

.vm-linkbtn {
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--vt-accent);
    font: inherit;
    cursor: pointer;
}
</style>
