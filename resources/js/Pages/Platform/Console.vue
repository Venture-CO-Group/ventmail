<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtCheckbox, VtField, VtInput, VtSelect, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * PLATFORM-KONZOL. Ez nem tenant-felület: tenanteken átnyúlva olvas, és minden művelete
 * auditált.
 *
 * A képernyő SEMMIT nem kerül meg: az írás-tilalmat aktív támogatói nézet alatt a
 * middleware és a modell-őr adja, a gombok itt vannak, a szerver dönt.
 */
const props = defineProps<{
    health: { status: string; components: Record<string, { status: string; detail: string | null }> };
    warmup: Array<{ address: string; pool: string; status: string; warmup_state: string; utilisation: number | null; workspace_id: number | null }>;
    impersonation: { workspace: string; reason: string; expiresAt: string } | null;
    workspaces: Array<{ uuid: string; name: string }>;
    overrides: Array<{ uuid: string; workspace: string; features: string[]; sendQuota: number | null; reason: string; expiresAt: string | null }>;
    globalSuppressions: Array<{ uuid: string; email: string; reason: string; detail: string | null; createdAt: string | null }>;
    features: string[];
    reasons: string[];
}>();

const { t } = useI18n();

const impersonationForm = ref({ workspace: props.workspaces[0]?.uuid ?? '', reason: '' });
const overrideForm = ref({
    workspace: props.workspaces[0]?.uuid ?? '',
    features: [] as string[],
    sendQuota: '',
    reason: '',
    expiresAt: '',
});
const suppressionForm = ref({ email: '', reason: props.reasons[0] ?? 'policy', detail: '' });
const removalReason = ref('');

const workspaceOptions = computed(() => props.workspaces.map((workspace) => ({ value: workspace.uuid, label: workspace.name })));
const reasonOptions = computed(() => props.reasons.map((value) => ({ value, label: value })));

// A NEM ZÖLD komponens a lényeg: az „ismeretlen" NEM egészséges, csak nem tudjuk.
const unhealthy = computed(() =>
    Object.entries(props.health.components).filter(([, component]) => !['healthy', 'disabled'].includes(component.status)),
);

type HealthRow = { component: string; status: string; detail: string };

const healthColumns: VtColumn<HealthRow>[] = [
    { key: 'component', label: t('platform.component') },
    { key: 'status', label: t('platform.status') },
    { key: 'detail', label: t('platform.detail') },
];

const healthRows = computed<HealthRow[]>(() =>
    Object.entries(props.health.components).map(([name, component]) => ({
        component: name,
        status: component.status,
        detail: component.detail ?? '—',
    })),
);

function toggleFeature(feature: string): void {
    overrideForm.value.features = overrideForm.value.features.includes(feature)
        ? overrideForm.value.features.filter((item) => item !== feature)
        : [...overrideForm.value.features, feature];
}

function startImpersonation(): void {
    router.post('/platform/impersonation', impersonationForm.value);
}

function endImpersonation(): void {
    router.post('/platform/impersonation/end');
}

function grantOverride(): void {
    router.post(
        '/platform/overrides',
        {
            workspace: overrideForm.value.workspace,
            features: overrideForm.value.features,
            sendQuota: overrideForm.value.sendQuota === '' ? null : Number(overrideForm.value.sendQuota),
            reason: overrideForm.value.reason,
            expiresAt: overrideForm.value.expiresAt,
        },
        { preserveScroll: true },
    );
}

function revokeOverride(uuid: string): void {
    router.delete(`/platform/overrides/${uuid}`, { preserveScroll: true });
}

function addSuppression(): void {
    router.post('/platform/suppressions', suppressionForm.value, { preserveScroll: true });
}

function removeSuppression(uuid: string): void {
    router.delete(`/platform/suppressions/${uuid}`, {
        data: { detail: removalReason.value },
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('platform.title') }}</h1>

        <p v-if="unhealthy.length" class="vm-banner vm-banner--danger" role="status" data-testid="health-warning">
            {{ t('platform.unhealthyWarning', { count: unhealthy.length, status: health.status }) }}
        </p>

        <VtCard>
            <h2>{{ t('platform.health') }}</h2>
            <VtTable :columns="healthColumns" :rows="healthRows" :caption="t('platform.health')" data-testid="health-table" />
        </VtCard>

        <VtCard>
            <h2>{{ t('platform.impersonation') }}</h2>

            <template v-if="impersonation">
                <p data-testid="impersonation-active">
                    {{ t('platform.impersonationActive', { workspace: impersonation.workspace, reason: impersonation.reason }) }}
                </p>
                <!-- A LEZÁRÁS az EGYETLEN írás, ami aktív nézet alatt átmegy — enélkül nem
                     lehetne kilépni belőle. -->
                <VtButton data-testid="impersonation-end" @click="endImpersonation">
                    {{ t('platform.endImpersonation') }}
                </VtButton>
            </template>

            <template v-else>
                <VtField :label="t('platform.workspace')">
                    <VtSelect v-model="impersonationForm.workspace" :options="workspaceOptions" data-testid="impersonation-workspace" />
                </VtField>

                <VtField :label="t('platform.reason')" :hint="t('platform.reasonHint')">
                    <VtInput v-model="impersonationForm.reason" data-testid="impersonation-reason" placeholder="SUP-123" />
                </VtField>

                <VtButton
                    :disabled="!impersonationForm.reason.trim()"
                    data-testid="impersonation-start"
                    @click="startImpersonation"
                >
                    {{ t('platform.startImpersonation') }}
                </VtButton>
            </template>
        </VtCard>

        <VtCard>
            <h2>{{ t('platform.overrides') }}</h2>

            <VtField :label="t('platform.workspace')">
                <VtSelect v-model="overrideForm.workspace" :options="workspaceOptions" data-testid="override-workspace" />
            </VtField>

            <fieldset>
                <legend>{{ t('platform.features') }}</legend>
                <div class="vm-cluster">
                    <VtCheckbox
                        v-for="feature in features"
                        :key="feature"
                        :model-value="overrideForm.features.includes(feature)"
                        :data-testid="`override-feature-${feature}`"
                        @update:model-value="toggleFeature(feature)"
                    >
                        {{ feature }}
                    </VtCheckbox>
                </div>
            </fieldset>

            <VtField :label="t('platform.sendQuota')" :hint="t('platform.sendQuotaHint')">
                <VtInput v-model="overrideForm.sendQuota" data-testid="override-quota" placeholder="500000" />
            </VtField>

            <VtField :label="t('platform.reason')">
                <VtInput v-model="overrideForm.reason" data-testid="override-reason" />
            </VtField>

            <VtField :label="t('platform.expiresAt')" :hint="t('platform.expiresHint')">
                <VtInput v-model="overrideForm.expiresAt" data-testid="override-expires" placeholder="2026-12-31 23:59" />
            </VtField>

            <VtButton data-testid="override-submit" @click="grantOverride">{{ t('platform.grantOverride') }}</VtButton>

            <div v-for="override in overrides" :key="override.uuid" class="vm-cluster" :data-testid="`override-${override.uuid}`">
                <strong>{{ override.workspace }}</strong>
                <span>{{ override.features.join(', ') || '—' }}</span>
                <span>{{ override.sendQuota ?? '—' }}</span>
                <span>{{ override.reason }}</span>
                <VtButton variant="ghost" :data-testid="`revoke-override-${override.uuid}`" @click="revokeOverride(override.uuid)">
                    {{ t('platform.revoke') }}
                </VtButton>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('platform.globalSuppressions') }}</h2>
            <p>{{ t('platform.globalSuppressionNote') }}</p>

            <VtField :label="t('platform.email')">
                <VtInput v-model="suppressionForm.email" data-testid="global-suppression-email" />
            </VtField>

            <VtField :label="t('platform.reason')">
                <VtSelect v-model="suppressionForm.reason" :options="reasonOptions" data-testid="global-suppression-reason" />
            </VtField>

            <VtField :label="t('platform.detail')" :hint="t('platform.detailHint')">
                <VtInput v-model="suppressionForm.detail" data-testid="global-suppression-detail" />
            </VtField>

            <VtButton data-testid="global-suppression-submit" @click="addSuppression">
                {{ t('platform.addSuppression') }}
            </VtButton>

            <VtField :label="t('platform.removalReason')">
                <VtInput v-model="removalReason" data-testid="global-removal-reason" />
            </VtField>

            <div v-for="item in globalSuppressions" :key="item.uuid" class="vm-cluster" :data-testid="`global-${item.uuid}`">
                <span class="vt-text-mono">{{ item.email }}</span>
                <span>{{ item.reason }}</span>
                <VtButton
                    variant="ghost"
                    :disabled="!removalReason.trim()"
                    :data-testid="`remove-global-${item.uuid}`"
                    @click="removeSuppression(item.uuid)"
                >
                    {{ t('platform.remove') }}
                </VtButton>
            </div>
        </VtCard>

        <VtCard>
            <h2>{{ t('platform.warmup') }}</h2>

            <div v-for="ip in warmup" :key="ip.address" class="vm-cluster">
                <span class="vt-text-mono">{{ ip.address }}</span>
                <span>{{ ip.pool }}</span>
                <span>{{ ip.status }} · {{ ip.warmup_state }}</span>
                <span>{{ ip.utilisation === null ? '—' : `${ip.utilisation}%` }}</span>
            </div>
        </VtCard>

        <VtCard>
            <Link href="/platform/audit" data-testid="audit-link">{{ t('platform.auditSearch') }}</Link>
        </VtCard>
    </AppLayout>
</template>
