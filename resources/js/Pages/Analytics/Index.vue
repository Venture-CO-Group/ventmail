<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { VtCard } from '@venture/ui';
import { useI18n } from 'vue-i18n';
import AppLayout from '../../Layouts/AppLayout.vue';

/** ANALITIKA: a KIKÜLDÖTT kampányok listája — riport csak arról van, ami elment. */
defineProps<{ campaigns: Array<{ uuid: string; name: string; status: string }> }>();

const { t } = useI18n();
</script>

<template>
    <AppLayout>
        <h1 class="vt-text-display">{{ t('analytics.title') }}</h1>

        <VtCard>
            <p v-if="!campaigns.length" data-testid="analytics-empty">{{ t('analytics.empty') }}</p>

            <div v-for="campaign in campaigns" :key="campaign.uuid" class="vm-cluster">
                <Link :href="`/analytics/campaigns/${campaign.uuid}`" :data-testid="`report-${campaign.uuid}`">
                    {{ campaign.name }}
                </Link>
                <span>{{ campaign.status }}</span>
            </div>
        </VtCard>
    </AppLayout>
</template>
