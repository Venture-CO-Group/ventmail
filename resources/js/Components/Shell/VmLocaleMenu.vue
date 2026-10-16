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
import { VtDropdownItem } from '@venture-co-group/ui';
import VmIcon from './VmIcon.vue';

/** `menu`: a `VtTopbar` nyelvi menüjének tételei (`locale-menu` slot); `inline`: gombsor a fiókmenüben. */
withDefaults(defineProps<{ variant?: 'menu' | 'inline' }>(), { variant: 'menu' });

const page = usePage<{ locale: string; supportedLocales: string[] }>();
const { t, locale, setLocaleMessage } = useI18n({ useScope: 'global' });

const locales = computed(() => page.props.supportedLocales ?? []);
const current = computed(() => page.props.locale);

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
    <template v-if="variant === 'menu'">
        <VtDropdownItem
            v-for="code in locales"
            :key="code"
            role="menuitemradio"
            :checked="code === current"
            :lang="code"
            :data-testid="`locale-option-${code}`"
            @select="choose(code)"
        >
            <template #icon><span class="vm-menuitem__code vt-mono">{{ code.toUpperCase() }}</span></template>
            {{ t(`shell.languageName.${code}`) }}
            <template v-if="code === current" #trailing><VmIcon name="check" /></template>
        </VtDropdownItem>
    </template>

    <div v-else class="vm-langrow" role="group" :aria-label="t('shell.languageMenu')">
        <button
            v-for="code in locales"
            :key="code"
            type="button"
            class="vm-langrow__btn vt-mono"
            :aria-pressed="code === current"
            :lang="code"
            :title="t(`shell.languageName.${code}`)"
            :data-testid="`locale-inline-${code}`"
            @click="choose(code)"
        >
            {{ code.toUpperCase() }}
        </button>
    </div>
</template>
