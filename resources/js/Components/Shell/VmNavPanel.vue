<script setup lang="ts">
/*
 | Egy nav-csoport PANELJE: a menüpontok ikonnal, felirattal és egysoros leírással.
 | A „hamarosan" jelzés (D15) badge — a link attól még működik.
 |
 | A menüpont a @venture/ui `VtNavItem` (0.3.0, D316): az AKTÍV állapot accent szöveg/ikon +
 | bal oldali jelölő-csík, háttérkitöltés NÉLKÜL; hoverre finom háttér. A desktop legördülő és
 | a mobil harmonika UGYANEZT a komponenst használja.
 */
import { Link } from '@inertiajs/vue3';
import { VtBadge, VtNavItem } from '@venture/ui';
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
            <VtNavItem
                :as="Link"
                :href="item.href"
                :label="item.label"
                :description="showDescription ? item.description : undefined"
                :active="activeHref === item.href"
                :data-testid="item.testid"
            >
                <template #icon><VmIcon :name="item.icon" /></template>
                <template v-if="item.soon" #trailing>
                    <VtBadge tone="accent" size="sm" class="vm-navitem__soon">{{ t('nav.soon') }}</VtBadge>
                </template>
            </VtNavItem>
        </li>
    </ul>
</template>
