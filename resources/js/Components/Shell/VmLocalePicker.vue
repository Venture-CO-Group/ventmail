<script setup lang="ts">
/*
 | NYELVVÁLASZTÓ (HU/EN/DE/FR/IT/ES/RU). Menü-minta: `menuitemradio`, nyilakkal járható.
 |
 | ELŐBB A SZÓTÁR, aztán a váltás: a bundle-ök lusták (nyelvenként külön chunk), tehát a
 | kiválasztott nyelv szótára a boot óta NINCS a memóriában. Ha csak a `locale`-t állítanánk
 | át, a felület a fallback nyelvre esne vissza — mérve: magyarról németre váltva ANGOL
 | felirat jött. A NYELV a felhasználó sorára megy (`locale_source = user`), ezért
 | szerver-hívás is kell.
 */
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { loadLocaleMessages } from '../../i18n';
import { VtDropdown, VtDropdownItem } from '@venture/ui';
import { asTrigger, useShellMenu } from '../../composables/useMenuState';
import VmIcon from './VmIcon.vue';

/** `menu` (legördülő) vagy `inline` (a hamburger-panel gombsora). */
withDefaults(defineProps<{ variant?: 'menu' | 'inline' }>(), { variant: 'menu' });

const page = usePage<{ locale: string; supportedLocales: string[] }>();
const { t, locale, setLocaleMessage } = useI18n({ useScope: 'global' });

const locales = computed(() => page.props.supportedLocales ?? []);
const current = computed(() => page.props.locale);

const menu = useShellMenu('locale');

// A menüpont választása a `VtDropdown`-t zárja (és a fókuszt a gombra adja vissza).
async function choose(code: string): Promise<void> {
    if (!code || code === page.props.locale) return;

    setLocaleMessage(code, (await loadLocaleMessages(code)) as never);
    locale.value = code;

    router.post('/locale', { locale: code }, { preserveScroll: true });
}
</script>

<template>
    <!-- D315: @venture/ui `VtDropdown` menü-módban; a tételek `VtDropdownItem` (menuitemradio). -->
    <VtDropdown v-if="variant === 'menu'" mode="menu" align="end" :label="t('shell.languageMenu')" :open="menu.open.value" @update:open="menu.setOpen">
        <template #trigger="{ open, ...trigger }">
            <button type="button" class="vm-tool" v-bind="asTrigger(trigger)" data-testid="locale-switch" :data-open="open ? 'true' : undefined">
                <VmIcon name="globe" />
                <span class="vm-tool__code vt-text-mono" data-testid="locale-current">{{ current.toUpperCase() }}</span>
            </button>
        </template>

        <VtDropdownItem
            v-for="code in locales"
            :key="code"
            role="menuitemradio"
            :checked="code === current"
            :lang="code"
            :data-testid="`locale-option-${code}`"
            @select="choose(code)"
        >
            <template #icon><span class="vm-menuitem__code vt-text-mono">{{ code.toUpperCase() }}</span></template>
            {{ t(`shell.languageName.${code}`) }}
            <template v-if="code === current" #trailing><VmIcon name="check" /></template>
        </VtDropdownItem>
    </VtDropdown>

    <div v-else class="vm-langrow" role="group" :aria-label="t('shell.languageMenu')">
        <button
            v-for="code in locales"
            :key="code"
            type="button"
            class="vm-langrow__btn vt-text-mono"
            :aria-pressed="code === current"
            :lang="code"
            :title="t(`shell.languageName.${code}`)"
            :data-testid="`locale-option-${code}`"
            @click="choose(code)"
        >
            {{ code.toUpperCase() }}
        </button>
    </div>
</template>
