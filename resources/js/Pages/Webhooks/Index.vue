<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtButton, VtCard, VtCheckbox, VtField, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * WEBHOOK-VÉGPONTOK ÉS KÉZBESÍTÉSEK — egy képernyőn, mert a kérdés is egy:
 * „miért nem kapom meg az eseményeket?"
 */
interface Endpoint {
    uuid: string;
    name: string;
    url: string;
    events: string[];
    active: boolean;
    lastSuccessAt: string | null;
}

interface Delivery {
    uuid: string;
    endpoint: string;
    event: string;
    status: string;
    attempt: number;
    responseCode: number | null;
    nextAttemptAt: string | null;
    deadLetteredAt: string | null;
    createdAt: string | null;
    replayable: boolean;
}

const props = defineProps<{
    status: string;
    endpoints: Endpoint[];
    deliveries: Delivery[];
    availableEvents: string[];
}>();

const { t } = useI18n();
const page = usePage<{ flash: { secret: { kind: string; value?: string } | null } }>();

const name = ref('');
const url = ref('');
const events = ref<string[]>([]);

const issuedSecret = computed(() => {
    const secret = page.props.flash?.secret;

    return secret?.kind === 'webhook' ? (secret.value ?? null) : null;
});

const deadLettered = computed(() => props.deliveries.filter((delivery) => delivery.deadLetteredAt !== null));

type DeliveryRow = { endpoint: string; event: string; status: string; attempt: string; code: string; created: string };

const deliveryColumns: VtColumn<DeliveryRow>[] = [
    { key: 'created', label: t('webhooks.time') },
    { key: 'endpoint', label: t('webhooks.endpoint') },
    { key: 'event', label: t('webhooks.event') },
    { key: 'status', label: t('webhooks.status') },
    { key: 'attempt', label: t('webhooks.attempt') },
    { key: 'code', label: t('webhooks.code') },
];

const deliveryRows = computed<DeliveryRow[]>(() =>
    props.deliveries.map((delivery) => ({
        endpoint: delivery.endpoint,
        event: delivery.event,
        status: delivery.deadLetteredAt ? t('webhooks.deadLettered') : delivery.status,
        attempt: String(delivery.attempt),
        code: delivery.responseCode === null ? '—' : String(delivery.responseCode),
        created: delivery.createdAt ? new Date(delivery.createdAt).toLocaleString() : '—',
    })),
);

function toggleEvent(value: string): void {
    events.value = events.value.includes(value)
        ? events.value.filter((item) => item !== value)
        : [...events.value, value];
}

function create(): void {
    if (!name.value.trim() || !url.value.trim()) return;

    router.post(
        '/webhooks',
        { name: name.value.trim(), url: url.value.trim(), events: events.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                name.value = '';
                url.value = '';
                events.value = [];
            },
        },
    );
}

function remove(uuid: string): void {
    router.delete(`/webhooks/${uuid}`, { preserveScroll: true });
}

function replay(uuid: string): void {
    router.post(`/webhooks/deliveries/${uuid}/replay`, {}, { preserveScroll: true });
}

function filterStatus(value: string): void {
    router.get('/webhooks', value ? { status: value } : {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('webhooks.title') }}</h1>

        <!-- A DEAD-LETTER az, amiért ez a képernyő létezik: enélkül a kiesett esemény
             csendben elveszne. -->
        <p v-if="deadLettered.length" class="vm-banner vm-banner--danger" role="status" data-testid="dead-letter-warning">
            {{ t('webhooks.deadLetterWarning', { count: deadLettered.length }) }}
        </p>

        <VtCard v-if="issuedSecret" data-testid="issued-secret">
            <p>{{ t('webhooks.secretOnce') }}</p>
            <p class="vt-text-mono" data-testid="issued-secret-value">{{ issuedSecret }}</p>
        </VtCard>

        <VtCard>
            <h2>{{ t('webhooks.newEndpoint') }}</h2>

            <VtField :label="t('webhooks.name')">
                <VtInput v-model="name" data-testid="endpoint-name" placeholder="CRM" />
            </VtField>

            <VtField :label="t('webhooks.url')" :hint="t('webhooks.urlHint')">
                <VtInput v-model="url" data-testid="endpoint-url" placeholder="https://pelda.hu/hook" />
            </VtField>

            <fieldset>
                <legend>{{ t('webhooks.events') }}</legend>
                <p>{{ t('webhooks.eventsHint') }}</p>
                <div class="vm-cluster" data-testid="endpoint-events">
                    <VtCheckbox
                        v-for="event in availableEvents"
                        :key="event"
                        :model-value="events.includes(event)"
                        :data-testid="`event-${event}`"
                        @update:model-value="toggleEvent(event)"
                    >
                        {{ event }}
                    </VtCheckbox>
                </div>
            </fieldset>

            <VtButton :disabled="!name.trim() || !url.trim()" data-testid="endpoint-submit" @click="create">
                {{ t('webhooks.create') }}
            </VtButton>
        </VtCard>

        <VtCard>
            <h2>{{ t('webhooks.endpoints') }}</h2>

            <p v-if="!endpoints.length" data-testid="endpoints-empty">{{ t('webhooks.noEndpoints') }}</p>

            <div v-for="endpoint in endpoints" :key="endpoint.uuid" class="vm-cluster" :data-testid="`endpoint-${endpoint.uuid}`">
                <strong>{{ endpoint.name }}</strong>
                <span class="vt-text-mono">{{ endpoint.url }}</span>
                <span>{{ endpoint.events.length ? endpoint.events.join(', ') : t('webhooks.allEvents') }}</span>
                <span>{{ endpoint.active ? t('webhooks.active') : t('webhooks.inactive') }}</span>
                <VtButton variant="ghost" :data-testid="`delete-${endpoint.uuid}`" @click="remove(endpoint.uuid)">
                    {{ t('webhooks.delete') }}
                </VtButton>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('webhooks.deliveries') }}</h2>

            <div class="vm-cluster">
                <VtButton variant="ghost" data-testid="filter-all" @click="filterStatus('')">
                    {{ t('webhooks.filterAll') }}
                </VtButton>
                <VtButton variant="ghost" data-testid="filter-failed" @click="filterStatus('failed')">
                    {{ t('webhooks.filterFailed') }}
                </VtButton>
            </div>

            <p v-if="!deliveryRows.length" data-testid="deliveries-empty">{{ t('webhooks.noDeliveries') }}</p>
            <VtTable
                v-else
                :columns="deliveryColumns"
                :rows="deliveryRows"
                :caption="t('webhooks.deliveries')"
                data-testid="deliveries-table"
            />

            <div v-for="delivery in deliveries" :key="delivery.uuid" class="vm-cluster">
                <span>{{ delivery.event }}</span>
                <VtButton
                    v-if="delivery.replayable"
                    variant="ghost"
                    :data-testid="`replay-${delivery.uuid}`"
                    @click="replay(delivery.uuid)"
                >
                    {{ t('webhooks.replay') }}
                </VtButton>
            </div>
        </VtCard>
    </AppLayout>
</template>
