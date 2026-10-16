<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtField, VtGlowLine, VtInput, type VtColumn } from '@venture-co-group/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import VmForm from '../../Components/Form/VmForm.vue';
import VmFormActions from '../../Components/Form/VmFormActions.vue';
import VmFormSection from '../../Components/Form/VmFormSection.vue';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useI18n } from 'vue-i18n';

/**
 * Domain-hitelesítő wizard.
 *
 * Kizárólag @venture/ui komponensekből épül: nincs lokális gomb, kártya vagy táblázat, és
 * nincs beégetett szín/spacing/font — a design forrása a @venture/design token-készlet.
 */

interface DnsRecord {
    type: string;
    name: string;
    value: string;
    purpose: string;
    status: string;
}

const props = defineProps<{
    domain: { uuid: string; name: string; status: string } | null;
    records: Record<string, DnsRecord>;
}>();

const { t } = useI18n({ useScope: 'global' });

const domainName = ref('');
const checking = ref(false);
const submitting = ref(false);

const step = computed(() => {
    if (!props.domain) return 1;

    return props.domain.status === 'verified' ? 3 : 2;
});

const rows = computed(() =>
    Object.entries(props.records).map(([key, record]) => ({
        key,
        type: record.type,
        name: record.name,
        value: record.value,
        status: record.status,
    })),
);

type Row = { key: string; type: string; name: string; value: string; status: string };

const columns = (): VtColumn<Row>[] => [
    { key: 'type', label: t('wizard.type') },
    { key: 'name', label: t('wizard.name'), mono: true },
    { key: 'value', label: t('wizard.value'), mono: true },
    { key: 'status', label: t('wizard.rowStatus') },
];

function submitDomain(): void {
    if (!domainName.value.trim()) return;

    submitting.value = true;
    router.post('/domains', { name: domainName.value.trim() }, {
        onFinish: () => (submitting.value = false),
    });
}

function verify(): void {
    if (!props.domain) return;

    checking.value = true;
    router.post(`/domains/${props.domain.uuid}/verify`, {}, {
        onFinish: () => (checking.value = false),
    });
}

function rotateDkim(): void {
    if (!props.domain) return;

    router.post(`/domains/${props.domain.uuid}/dkim/rotate`);
}
</script>

<template>
    <AppLayout>
            <VtGlowLine />

            <VmCard>
                <h1>{{ t('wizard.title') }}</h1>
                <p>
                    {{ t('wizard.rule') }}
                </p>

                <ol data-testid="wizard-steps">
                    <li :aria-current="step === 1 ? 'step' : undefined">{{ t('wizard.step1') }}</li>
                    <li :aria-current="step === 2 ? 'step' : undefined">{{ t('wizard.step2') }}</li>
                    <li :aria-current="step === 3 ? 'step' : undefined">{{ t('wizard.step3') }}</li>
                </ol>
            </VmCard>

            <VmCard v-if="!domain">
                <VmForm @submit.prevent="submitDomain">
                    <VmFormSection>
                        <VtField :label="t('wizard.domain')" :hint="t('wizard.domainHint')">
                            <VtInput v-model="domainName" data-testid="domain-input" placeholder="pelda.hu" />
                        </VtField>
                    </VmFormSection>

                    <VmFormActions>
                        <VtButton type="submit" data-testid="domain-submit" :disabled="submitting">
                            {{ t('wizard.add') }}
                        </VtButton>
                    </VmFormActions>
                </VmForm>
            </VmCard>

            <VmCard v-else>
                <h2 class="vm-wrap">{{ domain.name }}</h2>
                <p data-testid="domain-status">{{ t('wizard.status', { status: domain.status }) }}</p>

                <VmTable
                    :columns="columns()"
                    :rows="rows"
                    :caption="t('wizard.dnsCaption')"
                    data-testid="dns-records"
                />

                <VmFormActions>
                    <VtButton data-testid="verify-button" :disabled="checking" @click="verify">
                        {{ t('wizard.verify') }}
                    </VtButton>

                    <VtButton data-testid="rotate-button" @click="rotateDkim">{{ t('wizard.rotate') }}</VtButton>
                </VmFormActions>
            </VmCard>
    </AppLayout>
</template>

<!--
  MEGJEGYZÉS a @venture/ui felé: hiányzik egy függőleges ritmust adó layout-primitív
  (stack/spacing). A golden rule szerint hiányzó komponenst JELENTENI kell, nem lokálisan
  gyártani — ezért itt sincs saját osztály, a kártyák a komponensek saját margóival élnek.
-->
