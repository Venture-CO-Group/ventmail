<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { VtButton, VtCard, VtField, VtGlowLine, VtInput, VtTable, type VtColumn } from '@venture/ui';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

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

const columns: VtColumn<Row>[] = [
    { key: 'type', label: 'Típus' },
    { key: 'name', label: 'Név', mono: true },
    { key: 'value', label: 'Érték', mono: true },
    { key: 'status', label: 'Állapot' },
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

            <VtCard>
                <h1>Küldő domain hitelesítése</h1>
                <p>
                    Verifikálatlan domainről a ventMail nem küld — ez nem beállítás, hanem szabály.
                    A három rekord (SPF, DKIM, DMARC) mindegyike kell hozzá.
                </p>

                <ol data-testid="wizard-steps">
                    <li :aria-current="step === 1 ? 'step' : undefined">Domain megadása</li>
                    <li :aria-current="step === 2 ? 'step' : undefined">DNS-rekordok felvétele</li>
                    <li :aria-current="step === 3 ? 'step' : undefined">Ellenőrzés</li>
                </ol>
            </VtCard>

            <VtCard v-if="!domain">
                <VtField label="Domain" hint="Például: pelda.hu — a feladó címének domainje.">
                    <VtInput v-model="domainName" data-testid="domain-input" placeholder="pelda.hu" />
                </VtField>

                <VtButton data-testid="domain-submit" :disabled="submitting" @click="submitDomain">
                    Domain hozzáadása
                </VtButton>
            </VtCard>

            <VtCard v-else>
                <h2>{{ domain.name }}</h2>
                <p data-testid="domain-status">Állapot: {{ domain.status }}</p>

                <VtTable
                    :columns="columns"
                    :rows="rows"
                    caption="A hitelesítéshez felveendő DNS-rekordok"
                    data-testid="dns-records"
                />

                <VtButton data-testid="verify-button" :disabled="checking" @click="verify">
                    Ellenőrzés indítása
                </VtButton>

                <VtButton data-testid="rotate-button" @click="rotateDkim">DKIM-kulcs rotálása</VtButton>
            </VtCard>
    </AppLayout>
</template>

<!--
  MEGJEGYZÉS a @venture/ui felé: hiányzik egy függőleges ritmust adó layout-primitív
  (stack/spacing). A golden rule szerint hiányzó komponenst JELENTENI kell, nem lokálisan
  gyártani — ezért itt sincs saját osztály, a kártyák a komponensek saját margóival élnek.
-->
