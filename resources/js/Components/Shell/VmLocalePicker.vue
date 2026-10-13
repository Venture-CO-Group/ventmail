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
import VmDropdown from './VmDropdown.vue';
import VmIcon from './VmIcon.vue';

/** `menu` (legördülő) vagy `inline` (a hamburger-panel gombsora). */
withDefaults(defineProps<{ variant?: 'menu' | 'inline' }>(), { variant: 'menu' });

const page = usePage<{ locale: string; supportedLocales: string[] }>();
const { t, locale, setLocaleMessage } = useI18n({ useScope: 'global' });

const locales = computed(() => page.props.supportedLocales ?? []);
const current = computed(() => page.props.locale);

async function choose(code: string, close?: (returnFocus?: boolean) => void): Promise<void> {
    close?.(true);

    if (!code || code === page.props.locale) return;

    setLocaleMessage(code, (await loadLocaleMessages(code)) as never);
    locale.value = code;

    router.post('/locale', { locale: code }, { preserveScroll: true });
}
</script>

<template>
    <VmDropdown
        v-if="variant === 'menu'"
        id="locale"
        mode="menu"
        align="end"
        testid="locale-switch"
        :label="t('shell.languageMenu')"
        trigger-class="vm-tool"
    >
        <template #trigger>
            <VmIcon name="globe" />
            <span class="vm-tool__code vt-text-mono" data-testid="locale-current">{{ current.toUpperCase() }}</span>
        </template>

        <template #default="{ close }">
            <button
                v-for="code in locales"
                :key="code"
                type="button"
                role="menuitemradio"
                class="vm-menuitem"
                :aria-checked="code === current"
                :lang="code"
                :data-testid="`locale-option-${code}`"
                @click="choose(code, close)"
            >
                <span class="vm-menuitem__code vt-text-mono">{{ code.toUpperCase() }}</span>
                <span class="vm-menuitem__label">{{ t(`shell.languageName.${code}`) }}</span>
                <VmIcon v-if="code === current" name="check" class="vm-menuitem__check" />
            </button>
        </template>
    </VmDropdown>

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
