<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { VtButton, type VtColumn } from '@venture/ui';
import VmTable from '../../Components/Form/VmTable.vue';
import VmCard from '../../Components/Form/VmCard.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * DOMAIN-LISTA. A wizard EGY domainről szól; itt derül ki, MELYIKRŐL lehet küldeni.
 *
 * A négy rész-állapot (SPF/DKIM/DMARC/tracking) külön oszlop: a „pending" önmagában nem
 * mondja meg, melyik DNS-rekord hiányzik, és a támogatás fél óráját pont az viszi el.
 */
interface DomainRow {
    uuid: string;
    name: string;
    status: string;
    spf: string;
    dkim: string;
    dmarc: string;
    tracking: string;
    verified_at: string | null;
}

const props = defineProps<{ domains: DomainRow[] }>();

const { t } = useI18n();

type Row = { name: string; status: string; spf: string; dkim: string; dmarc: string; tracking: string };

const columns: VtColumn<Row>[] = [
    { key: 'name', label: t('domains.name'), mono: true },
    { key: 'status', label: t('domains.status') },
    { key: 'spf', label: 'SPF' },
    { key: 'dkim', label: 'DKIM' },
    { key: 'dmarc', label: 'DMARC' },
    { key: 'tracking', label: t('domains.tracking') },
];

const rows = computed<Row[]>(() =>
    props.domains.map((domain) => ({
        name: domain.name,
        status: domain.status,
        spf: domain.spf,
        dkim: domain.dkim,
        dmarc: domain.dmarc,
        tracking: domain.tracking,
    })),
);

const unverified = computed(() => props.domains.filter((domain) => domain.status !== 'verified'));
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('domains.title') }}</h1>

        <!-- A HITELESÍTETLEN DOMAIN nem statisztika, hanem akadály: a PreSendGate 1. kapuja
             tiltja a küldést róla. Ezért figyelmeztetés, nem egy sor a többi között. -->
        <p v-if="unverified.length" class="vm-banner vm-banner--warning" role="status" data-testid="domains-unverified">
            {{ t('domains.unverified', { count: unverified.length }) }}
        </p>

        <VmCard>
            <div class="vm-cluster">
                <Link href="/domains/wizard">
                    <VtButton data-testid="domains-add">{{ t('domains.add') }}</VtButton>
                </Link>
            </div>
        </VmCard>

        <VmCard>
            <p v-if="!rows.length" data-testid="domains-empty">{{ t('domains.empty') }}</p>
            <VmTable
                v-else
                :columns="columns"
                :rows="rows"
                :caption="t('domains.title')"
                data-testid="domains-table"
            />
        </VmCard>
    </AppLayout>
</template>
