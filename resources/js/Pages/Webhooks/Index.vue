<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtButton, VtCheckbox, VtField, VtInput, VtModal, VtSegmented, VtSelect, type VtColumn } from '@venture/ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import VmCard from '../../Components/Form/VmCard.vue';
import VmFieldGroup from '../../Components/Form/VmFieldGroup.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormRow from '../../Components/Form/VmFormRow.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import VmTable from '../../Components/Form/VmTable.vue';
import VmSecretReveal from '../../Components/Platform/VmSecretReveal.vue';
import VmSplit from '../../Components/Platform/VmSplit.vue';
import VmStateBadge from '../../Components/Platform/VmStateBadge.vue';
import VmPageHeader from '../../Components/Studio/VmPageHeader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * WEBHOOKOK (v1.1 · 7. modul) — egy képernyőn, mert a kérdés is egy: „miért nem kapom meg
 * az eseményeket?"
 *
 * Bal oldalt a kiválasztott végpont: eseménytípusok, aláíró titok (csak az utolsó négy
 * karakter) rotációval, teszt-esemény küldése. Jobbra a végpontok és az újrapróbálási
 * menetrend; alul a kézbesítési napló szűrővel és újraküldéssel.
 */
interface Endpoint {
    uuid: string;
    name: string;
    url: string;
    events: string[];
    active: boolean;
    lastSuccessAt: string | null;
    lastFailureAt: string | null;
    secretHint: string;
    previousSecretUntil: string | null;
    total7d: number;
    ok7d: number;
    failed7d: number;
}

interface Delivery {
    uuid: string;
    endpoint: string;
    event: string;
    status: string;
    attempt: number;
    responseCode: number | null;
    responseExcerpt: string | null;
    durationMs: number | null;
    isTest: boolean;
    nextAttemptAt: string | null;
    deadLetteredAt: string | null;
    createdAt: string | null;
    replayable: boolean;
}

const props = defineProps<{
    status: string;
    endpointFilter: string | null;
    endpoints: Endpoint[];
    deliveries: Delivery[];
    availableEvents: string[];
    testEvents: string[];
    graceHours: number;
}>();

const { t, locale } = useI18n();
const page = usePage<{ flash: { secret: { kind: string; value?: string } | null } }>();

const creating = ref(false);
const name = ref('');
const url = ref('');
const events = ref<string[]>([]);

const selectedUuid = ref<string | null>(props.endpointFilter ?? props.endpoints[0]?.uuid ?? null);
const selected = computed(() => props.endpoints.find((endpoint) => endpoint.uuid === selectedUuid.value) ?? props.endpoints[0] ?? null);

const editEvents = ref<string[]>([]);
const testEvent = ref(props.testEvents[0] ?? 'delivered');
const rotating = ref(false);
const grace = ref(true);
const removing = ref(false);

watch(
    selected,
    (endpoint) => {
        editEvents.value = endpoint ? [...endpoint.events] : [];
    },
    { immediate: true },
);

const issuedSecret = computed(() => {
    const secret = page.props.flash?.secret;

    return secret?.kind === 'webhook' ? (secret.value ?? null) : null;
});

const deadLettered = computed(() => props.deliveries.filter((delivery) => delivery.deadLetteredAt !== null));

const statusOptions = computed(() => [
    { value: '', label: t('webhooks.filterAll') },
    { value: 'delivered', label: t('plat.webhooks.filterDelivered') },
    { value: 'failed', label: t('plat.webhooks.filterFailed') },
    { value: 'dead_letter', label: t('plat.state.dead_letter') },
]);

const eventOptions = computed(() => props.testEvents.map((event) => ({ value: event, label: event })));

type DeliveryRow = { created: string; event: string; endpoint: string; response: string; duration: string; attempt: string; uuid: string };

const deliveryColumns = computed<VtColumn<DeliveryRow>[]>(() => [
    { key: 'created', label: t('webhooks.time'), mono: true },
    { key: 'event', label: t('webhooks.event') },
    { key: 'endpoint', label: t('webhooks.endpoint') },
    { key: 'response', label: t('plat.webhooks.response') },
    { key: 'duration', label: t('plat.webhooks.latency'), align: 'right' },
    { key: 'attempt', label: t('webhooks.attempt') },
    { key: 'uuid', label: '' },
]);

const byUuid = computed(() => new Map(props.deliveries.map((delivery) => [delivery.uuid, delivery])));

const deliveryRows = computed<DeliveryRow[]>(() =>
    props.deliveries.map((delivery) => ({
        created: delivery.createdAt ? new Date(delivery.createdAt).toLocaleString(locale.value) : '—',
        event: delivery.event,
        endpoint: delivery.endpoint,
        response: delivery.responseCode === null ? (delivery.responseExcerpt ?? '—') : String(delivery.responseCode),
        duration: delivery.durationMs === null ? '—' : `${delivery.durationMs} ms`,
        attempt: `${delivery.attempt}/6${delivery.deadLetteredAt ? ` · ${t('plat.state.dead_letter')}` : ''}`,
        uuid: delivery.uuid,
    })),
);

/* Az újrapróbálási menetrend a `DeliverWebhook` exponenciális visszalépése (1 → 16 perc). */
const schedule = computed(() => [
    { label: t('plat.webhooks.attemptN', { n: 1 }), at: t('plat.webhooks.immediately') },
    ...[1, 2, 4, 8, 16].map((minutes, index) => ({ label: t('plat.webhooks.attemptN', { n: index + 2 }), at: `+${minutes} min` })),
    { label: t('plat.state.dead_letter'), at: t('plat.webhooks.replayable') },
]);

function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}

function create(): void {
    if (!name.value.trim() || !url.value.trim()) return;

    router.post(
        '/webhooks',
        { name: name.value.trim(), url: url.value.trim(), events: events.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                creating.value = false;
                name.value = '';
                url.value = '';
                events.value = [];
            },
        },
    );
}

function saveEvents(): void {
    if (!selected.value) return;

    router.patch(`/webhooks/${selected.value.uuid}`, { events: editEvents.value }, { preserveScroll: true });
}

function setActive(active: boolean): void {
    if (!selected.value) return;

    router.patch(`/webhooks/${selected.value.uuid}`, { active }, { preserveScroll: true });
}

function rotate(): void {
    if (!selected.value) return;

    router.post(`/webhooks/${selected.value.uuid}/rotate-secret`, { grace: grace.value }, {
        preserveScroll: true,
        onFinish: () => (rotating.value = false),
    });
}

function sendTest(): void {
    if (!selected.value) return;

    router.post(`/webhooks/${selected.value.uuid}/test`, { event: testEvent.value }, { preserveScroll: true });
}

function remove(): void {
    if (!selected.value) return;

    router.delete(`/webhooks/${selected.value.uuid}`, {
        preserveScroll: true,
        onFinish: () => (removing.value = false),
    });
}

function replay(uuid: string): void {
    router.post(`/webhooks/deliveries/${uuid}/replay`, {}, { preserveScroll: true });
}

function filter(status: string, endpoint: string | null = props.endpointFilter): void {
    const query: Record<string, string> = {};
    if (status) query.status = status;
    if (endpoint) query.endpoint = endpoint;

    router.get('/webhooks', query, { preserveState: true, preserveScroll: true });
}

function successRate(endpoint: Endpoint): string {
    return endpoint.total7d ? `${Math.round((endpoint.ok7d / endpoint.total7d) * 1000) / 10}%` : '—';
}
</script>

<template>
    <AppLayout>
        <VmPageHeader :eyebrow="t('plat.eyebrow.webhooks')" :title="t('webhooks.title')" :lead="t('plat.webhooks.lead')">
            <VtButton data-testid="endpoint-new" @click="creating = true">{{ t('plat.webhooks.add') }}</VtButton>
        </VmPageHeader>

        <!-- A DEAD-LETTER az, amiért ez a képernyő létezik: enélkül a kiesett esemény
             csendben elveszne. -->
        <p v-if="deadLettered.length" class="vm-banner vm-banner--danger" role="status" data-testid="dead-letter-warning">
            {{ t('webhooks.deadLetterWarning', { count: deadLettered.length }) }}
        </p>

        <VmSecretReveal
            v-if="issuedSecret"
            :title="t('plat.webhooks.secretTitle')"
            :hint="t('webhooks.secretOnce')"
            :value="issuedSecret"
            testid="issued-secret"
        />

        <VmCard v-if="!endpoints.length">
            <p data-testid="endpoints-empty">{{ t('webhooks.noEndpoints') }}</p>
        </VmCard>

        <VmSplit v-else reverse wide>
            <template #side>
                <VmCard>
                    <h2>{{ t('webhooks.endpoints') }}</h2>
                    <ul class="vm-endpoints" data-testid="endpoint-list">
                        <li v-for="endpoint in endpoints" :key="endpoint.uuid">
                            <button
                                type="button"
                                class="vm-endpoint"
                                :class="{ 'vm-endpoint--active': selected?.uuid === endpoint.uuid }"
                                :aria-pressed="selected?.uuid === endpoint.uuid"
                                :data-testid="`endpoint-${endpoint.uuid}`"
                                @click="selectedUuid = endpoint.uuid"
                            >
                                <span class="vm-endpoint__text">
                                    <strong class="vm-truncate" :title="endpoint.url">{{ endpoint.name }}</strong>
                                    <span class="vm-muted vm-truncate">
                                        {{ endpoint.events.length ? endpoint.events.join(', ') : t('webhooks.allEvents') }}
                                        · {{ t('plat.webhooks.rate7d', { rate: successRate(endpoint) }) }}
                                    </span>
                                </span>
                                <VmStateBadge :state="endpoint.active ? 'active' : 'disabled'" />
                            </button>
                        </li>
                    </ul>
                </VmCard>

                <VmCard>
                    <h2 class="vm-eyebrow">{{ t('plat.webhooks.schedule') }}</h2>
                    <dl class="vm-kv" data-testid="retry-schedule">
                        <template v-for="step in schedule" :key="step.label">
                            <dt>{{ step.label }}</dt>
                            <dd class="vt-text-mono">{{ step.at }}</dd>
                        </template>
                    </dl>
                </VmCard>
            </template>

            <VmCard v-if="selected" data-testid="endpoint-detail">
                <div class="vm-detail-head">
                    <h2 class="vm-wrap" data-testid="endpoint-url-title">{{ selected.url }}</h2>
                    <VmStateBadge :state="selected.active ? 'active' : 'disabled'" testid="endpoint-state" />
                </div>

                <VmFieldGroup :legend="t('plat.webhooks.eventTypes')" :hint="t('webhooks.eventsHint')" data-testid="edit-events">
                    <VtCheckbox
                        v-for="event in availableEvents"
                        :key="event"
                        :model-value="editEvents.includes(event)"
                        :data-testid="`edit-event-${event}`"
                        @update:model-value="editEvents = toggle(editEvents, event)"
                    >
                        {{ event }}
                    </VtCheckbox>
                </VmFieldGroup>

                <div class="vm-cluster">
                    <VtButton variant="glass" size="sm" data-testid="events-save" @click="saveEvents">{{ t('plat.save') }}</VtButton>
                    <VtButton variant="ghost" size="sm" data-testid="endpoint-toggle" @click="setActive(!selected.active)">
                        {{ selected.active ? t('plat.webhooks.disable') : t('plat.webhooks.enable') }}
                    </VtButton>
                    <VtButton variant="ghost" size="sm" :data-testid="`delete-${selected.uuid}`" @click="removing = true">
                        {{ t('webhooks.delete') }}
                    </VtButton>
                </div>

                <section class="vm-sub">
                    <h3>{{ t('plat.webhooks.secret') }}</h3>
                    <div class="vm-cluster">
                        <code class="vm-secret-mask" data-testid="secret-hint">whsec_••••••••••••{{ selected.secretHint }}</code>
                        <VtButton variant="glass" size="sm" data-testid="secret-rotate" @click="rotating = true">{{ t('plat.rotate') }}</VtButton>
                    </div>
                    <p class="vm-muted">
                        {{ t('plat.webhooks.signatureHint') }}
                        <template v-if="selected.previousSecretUntil">
                            {{ t('plat.webhooks.previousUntil', { at: new Date(selected.previousSecretUntil).toLocaleString(locale) }) }}
                        </template>
                    </p>
                </section>

                <section class="vm-sub">
                    <h3>{{ t('plat.webhooks.test') }}</h3>
                    <div class="vm-cluster">
                        <VtSelect v-model="testEvent" :options="eventOptions" data-testid="test-event" :aria-label="t('plat.webhooks.test')" />
                        <VtButton data-testid="test-send" :disabled="!selected.active" @click="sendTest">{{ t('plat.webhooks.send') }}</VtButton>
                    </div>
                    <p class="vm-muted">{{ t('plat.webhooks.testHint') }}</p>
                </section>
            </VmCard>
        </VmSplit>

        <VmCard>
            <div class="vm-detail-head">
                <h2>{{ t('plat.webhooks.log') }}</h2>
                <div class="vm-seg-scroll">
                    <VtSegmented
                        :model-value="status"
                        :options="statusOptions"
                        :label="t('webhooks.status')"
                        data-testid="delivery-filter"
                        @update:model-value="filter($event)"
                    />
                </div>
            </div>

            <p v-if="!deliveryRows.length" data-testid="deliveries-empty">{{ t('webhooks.noDeliveries') }}</p>
            <VmTable v-else :columns="deliveryColumns" :rows="deliveryRows" :caption="t('webhooks.deliveries')" data-testid="deliveries-table">
                <template #cell="{ row, column, value }">
                    <span v-if="column.key === 'event'" class="vm-cluster vm-nowrap">
                        <code class="vm-nowrap-text">{{ value }}</code>
                        <VmStateBadge v-if="byUuid.get(row.uuid)?.isTest" state="test" />
                    </span>
                    <span v-else-if="column.key === 'response'" class="vm-cluster vm-nowrap" :title="byUuid.get(row.uuid)?.responseExcerpt ?? ''">
                        <VmStateBadge :state="byUuid.get(row.uuid)?.status ?? 'pending'" :testid="`delivery-state-${row.uuid}`" />
                        <span class="vt-text-mono vm-truncate vm-excerpt">{{ value }}</span>
                    </span>
                    <VtButton
                        v-else-if="column.key === 'uuid'"
                        v-show="byUuid.get(row.uuid)?.replayable"
                        variant="ghost"
                        size="sm"
                        :data-testid="`replay-${row.uuid}`"
                        @click="replay(row.uuid)"
                    >
                        {{ t('plat.webhooks.resend') }}
                    </VtButton>
                    <span v-else class="vm-truncate" :title="String(value ?? '')">{{ value }}</span>
                </template>
            </VmTable>
        </VmCard>

        <VtModal :open="creating" :title="t('webhooks.newEndpoint')" @close="creating = false">
            <VmForm @submit.prevent="create">
                <VmFormSection>
                    <VmFormRow>
                        <VtField :label="t('webhooks.name')">
                            <VtInput v-model="name" data-testid="endpoint-name" placeholder="CRM" />
                        </VtField>

                        <VtField :label="t('webhooks.url')" :hint="t('webhooks.urlHint')">
                            <VtInput v-model="url" data-testid="endpoint-url" placeholder="https://pelda.hu/hook" />
                        </VtField>
                    </VmFormRow>

                    <VmFieldGroup :legend="t('webhooks.events')" :hint="t('webhooks.eventsHint')" data-testid="endpoint-events">
                        <VtCheckbox
                            v-for="event in availableEvents"
                            :key="event"
                            :model-value="events.includes(event)"
                            :data-testid="`event-${event}`"
                            @update:model-value="events = toggle(events, event)"
                        >
                            {{ event }}
                        </VtCheckbox>
                    </VmFieldGroup>
                </VmFormSection>

                <VmFormActions>
                    <VtButton variant="ghost" @click="creating = false">{{ t('plat.cancel') }}</VtButton>
                    <VtButton type="submit" :disabled="!name.trim() || !url.trim()" data-testid="endpoint-submit">
                        {{ t('webhooks.create') }}
                    </VtButton>
                </VmFormActions>
            </VmForm>
        </VtModal>

        <VtModal :open="rotating" :title="t('plat.webhooks.rotateTitle')" @close="rotating = false">
            <p>{{ t('plat.webhooks.rotateText') }}</p>
            <VtCheckbox v-model="grace" data-testid="rotate-grace">{{ t('plat.webhooks.grace', { hours: graceHours }) }}</VtCheckbox>
            <template #footer>
                <VtButton variant="ghost" @click="rotating = false">{{ t('plat.cancel') }}</VtButton>
                <VtButton data-testid="rotate-secret-confirm" @click="rotate">{{ t('plat.rotate') }}</VtButton>
            </template>
        </VtModal>

        <VtModal :open="removing" :title="t('webhooks.delete')" @close="removing = false">
            <p>{{ t('plat.webhooks.deleteConfirm', { name: selected?.name ?? '' }) }}</p>
            <template #footer>
                <VtButton variant="ghost" @click="removing = false">{{ t('plat.cancel') }}</VtButton>
                <VtButton data-testid="delete-confirm" @click="remove">{{ t('webhooks.delete') }}</VtButton>
            </template>
        </VtModal>
    </AppLayout>
</template>

<style scoped>
.vm-endpoints {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    max-height: calc(var(--vt-space-32) * 4);
    overflow-y: auto;
}

.vm-endpoint {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
    padding: var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background: transparent;
    color: var(--vt-text);
    font: inherit;
    text-align: left;
    cursor: pointer;
    min-width: 0;
}

.vm-endpoint--active {
    border-color: var(--vt-color-accent-500);
}

.vm-endpoint__text {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-1);
    min-width: 0;
}

.vm-muted {
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-body-sm);
}

.vm-eyebrow {
    margin: 0;
    color: var(--vt-text-muted);
    font-size: var(--vt-fs-caption);
    letter-spacing: var(--vt-tracking-wider);
    text-transform: uppercase;
}

.vm-kv {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: var(--vt-space-2) var(--vt-space-4);
    margin: 0;
}

.vm-kv dd {
    margin: 0;
}

.vm-detail-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--vt-space-3);
}

.vm-detail-head h2 {
    margin: 0;
    min-width: 0;
}

.vm-sub {
    display: flex;
    flex-direction: column;
    gap: var(--vt-space-2);
    padding-top: var(--vt-space-4);
    border-top: thin solid var(--vt-border);
}

.vm-sub h3,
.vm-sub p {
    margin: 0;
}

.vm-secret-mask {
    font-family: var(--vt-font-mono);
    font-size: var(--vt-fs-body-sm);
    padding: var(--vt-space-2) var(--vt-space-3);
    border: thin solid var(--vt-border);
    border-radius: var(--vt-radius-md);
    background: var(--vt-bg-elevated);
    overflow-wrap: anywhere;
}

.vm-seg-scroll {
    max-width: 100%;
    overflow-x: auto;
}

.vm-nowrap {
    flex-wrap: nowrap;
    min-width: 0;
}

.vm-nowrap-text {
    white-space: nowrap;
}

.vm-excerpt {
    max-width: calc(var(--vt-space-32) * 2);
}
</style>
