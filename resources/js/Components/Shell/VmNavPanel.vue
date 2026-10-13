<script setup lang="ts">
/*
 | Egy nav-csoport PANELJE: a menüpontok ikonnal, felirattal és egysoros leírással.
 | A „hamarosan" jelzés (D15) badge — a link attól még működik.
 */
import { Link } from '@inertiajs/vue3';
import { VtBadge } from '@venture/ui';
import { useI18n } from 'vue-i18n';
import type { NavItem } from '../../composables/useNavigation';
import VmIcon from './VmIcon.vue';

withDefaults(defineProps<{ items: NavItem[]; activeHref: string | null; showDescription?: boolean }>(), {
    showDescription: true,
});

const { t } = useI18n({ useScope: 'global' });
</script>

<template>
    <ul class="vm-navpanel" role="list">
        <li v-for="item in items" :key="item.href" class="vm-navpanel__li">
            <Link
                class="vm-navitem"
                :href="item.href"
                :data-testid="item.testid"
                :aria-current="activeHref === item.href ? 'page' : undefined"
            >
                <span class="vm-navitem__icon"><VmIcon :name="item.icon" /></span>
                <span class="vm-navitem__text">
                    <span class="vm-navitem__label">
                        {{ item.label }}
                        <VtBadge v-if="item.soon" tone="accent" size="sm" class="vm-navitem__soon">{{ t('nav.soon') }}</VtBadge>
                    </span>
                    <span v-if="showDescription" class="vm-navitem__desc">{{ item.description }}</span>
                </span>
            </Link>
        </li>
    </ul>
</template>
