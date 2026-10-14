<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtDropdown } from '@venture/ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BrandLogo from '../Components/BrandLogo.vue';
import VmIcon from '../Components/Shell/VmIcon.vue';
import VmLocalePicker from '../Components/Shell/VmLocalePicker.vue';
import VmNavPanel from '../Components/Shell/VmNavPanel.vue';
import VmUserMenu from '../Components/Shell/VmUserMenu.vue';
import VmWorkspaceSwitcher from '../Components/Shell/VmWorkspaceSwitcher.vue';
import { useCompactNav } from '../composables/useCompactNav';
import { useLocaleSync } from '../composables/useLocaleSync';
import { activeMenuId, asTrigger, closeAllMenus, setMenuOpen } from '../composables/useMenuState';
import { useNavigation, type NavGroupId } from '../composables/useNavigation';
import { readStoredList, writeStoredList } from '../composables/useStoredState';
import { useFormat } from '../composables/useFormat';

/**
 * AZ APP-HÉJ: FELSŐ NAVIGÁCIÓ (az oldalsáv megszűnt, hogy a szerkesztőknek legyen hely).
 *
 * Balról jobbra: logó · workspace-váltó · Overview · Studio ▾ · Platform ▾ · Settings ▾
 * (· Console ▾ platform-adminnak) — jobbra a nyelvválasztó és az avatar-menü.
 *
 * - DESKTOP: a csoport ráhúzásra vagy kattintásra nyílik, panelben az alpontok + egysoros
 *   leírás (`VmNavPanel`).
 * - TABLET ALATT: hamburger, harmonikaszerű csoportokkal; az utoljára kinyitott csoportok
 *   a `localStorage`-ban maradnak (try/catch-csel).
 * - SZERKESZTŐ MÓD (`editorMode`): kompakt, egysoros sáv, a csoportmenük ikonként — a
 *   sablonszerkesztő és a journey-vászon kér ilyet, hogy a vászon maximális helyet kapjon.
 *
 * A menü a KÉPESSÉGEKET követi (lásd `useNavigation`): KÉNYELEM, nem védelem.
 */
interface SharedProps {
    [key: string]: unknown;
    editorMode?: boolean;
    impersonation: { active: boolean; workspace: string; expiresAt: string } | null;
    flash: { success: string | null; error: string | null };
}

const props = withDefaults(defineProps<{ editorMode?: boolean }>(), { editorMode: false });

const page = usePage<SharedProps>();
// A kompakt sávot az oldal két módon kérheti: `<AppLayout editor-mode>` vagy az Inertia
// `editorMode: true` prop-ja (a kontroller dönt) — a kettő vagy-kapcsolata számít.
const isEditor = computed(() => props.editorMode || page.props.editorMode === true);
const { t } = useI18n({ useScope: 'global' });
const format = useFormat();
const { groups, activeHref, isOverviewActive } = useNavigation();
const compact = useCompactNav();

// A szerver a nyelv igazsága: ha a prop eltér a kliens nyelvétől (pl. a profil mentése után), követjük.
useLocaleSync();

const STORAGE_KEY = 'vm.nav.expanded';

const mobileOpen = ref(false);
const expanded = ref<string[]>([]);

let stopNavigate: (() => void) | undefined;

onMounted(() => {
    expanded.value = readStoredList(STORAGE_KEY);
    // Egy navigáció után egyetlen legördülő sem maradhat nyitva.
    stopNavigate = router.on('navigate', closeAllMenus);
});

onBeforeUnmount(() => stopNavigate?.());

function toggleGroup(id: NavGroupId): void {
    expanded.value = expanded.value.includes(id) ? expanded.value.filter((item) => item !== id) : [...expanded.value, id];
    writeStoredList(STORAGE_KEY, expanded.value);
}

// A hamburger-panel a navigáció (és a nézet-váltás) után zárul.
watch(
    () => page.url,
    () => {
        mobileOpen.value = false;
    },
);
watch(compact, (value) => {
    if (!value) mobileOpen.value = false;
});

const iconOnly = computed(() => isEditor.value && !compact.value);

function groupActive(id: NavGroupId): boolean {
    return groups.value.find((group) => group.id === id)?.items.some((item) => item.href === activeHref.value) ?? false;
}

function onMobileKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && mobileOpen.value) {
        mobileOpen.value = false;
        document.getElementById('nav-hamburger')?.focus();
    }
}
</script>

<template>
    <div
        class="vm-shell"
        :class="{ 'vm-shell--editor': isEditor, 'vm-shell--compact': compact }"
        :data-editor-mode="isEditor ? 'true' : undefined"
    >
        <a class="vm-visually-hidden" href="#main">{{ t('shell.skipToContent') }}</a>

        <header
            class="vm-topbar vt-glass"
            :class="{ 'vm-topbar--compact': compact, 'vm-topbar--editor': isEditor }"
            data-testid="app-nav"
            @keydown="onMobileKeydown"
        >
            <div class="vm-topbar__row">
                <div class="vm-topbar__brand">
                    <BrandLogo href="/dashboard" />
                </div>

                <VmWorkspaceSwitcher :compact="isEditor" />

                <nav v-if="!compact" class="vm-topbar__nav" :aria-label="t('nav.main')">
                    <Link
                        class="vm-toplink"
                        :class="{ 'vm-toplink--icon': iconOnly }"
                        href="/dashboard"
                        data-testid="nav-overview"
                        :aria-label="iconOnly ? t('nav.dashboard') : undefined"
                        :title="iconOnly ? t('nav.dashboard') : undefined"
                        :aria-current="isOverviewActive ? 'page' : undefined"
                    >
                        <VmIcon name="home" />
                        <span v-if="!iconOnly">{{ t('nav.dashboard') }}</span>
                    </Link>

                    <div v-for="group in groups" :key="group.id" class="vm-topbar__group" :data-testid="`nav-${group.id}`">
                        <!-- D315: @venture/ui `VtDropdown` (hover + kattintásra rögzít, Esc, ütközés-figyelés),
                             VEZÉRELT módban az „egyszerre egy nyitott" koordinációhoz. -->
                        <VtDropdown
                            hover
                            :label="iconOnly ? group.label : undefined"
                            :open="activeMenuId === `group-${group.id}`"
                            @update:open="setMenuOpen(`group-${group.id}`, $event)"
                        >
                            <template #trigger="{ open, ...trigger }">
                                <button
                                    type="button"
                                    class="vm-toplink"
                                    v-bind="asTrigger(trigger)"
                                    :title="iconOnly ? group.label : undefined"
                                    :data-testid="`nav-group-${group.id}`"
                                    :data-open="open ? 'true' : undefined"
                                >
                                    <span class="vm-toplink__inner" :data-active="groupActive(group.id) ? 'true' : undefined">
                                        <VmIcon :name="group.icon" />
                                        <span v-if="!iconOnly">{{ group.label }}</span>
                                        <VmIcon name="chevron" class="vm-toplink__chevron" :class="{ 'vm-toplink__chevron--open': open }" />
                                    </span>
                                </button>
                            </template>

                            <div :class="`vm-navmenu vm-navmenu--${group.id}`">
                                <VmNavPanel :items="group.items" :active-href="activeHref" />
                            </div>
                        </VtDropdown>
                    </div>
                </nav>

                <div class="vm-topbar__tools">
                    <VmLocalePicker v-if="!compact" />
                    <VmUserMenu />

                    <button
                        v-if="compact"
                        id="nav-hamburger"
                        type="button"
                        class="vm-tool vm-tool--square"
                        data-testid="nav-hamburger"
                        :aria-label="mobileOpen ? t('nav.closeMenu') : t('nav.openMenu')"
                        :aria-expanded="mobileOpen"
                        aria-controls="nav-mobile"
                        @click="mobileOpen = !mobileOpen"
                    >
                        <VmIcon :name="mobileOpen ? 'close' : 'menu'" />
                    </button>
                </div>
            </div>

            <!-- HAMBURGER-PANEL: harmonikaszerű csoportok (tablet-töréspont alatt). -->
            <nav
                v-if="compact && mobileOpen"
                id="nav-mobile"
                class="vm-mobilenav"
                data-testid="nav-mobile"
                :aria-label="t('nav.main')"
            >
                <Link
                    class="vm-mobilenav__top"
                    href="/dashboard"
                    data-testid="nav-overview"
                    :aria-current="isOverviewActive ? 'page' : undefined"
                >
                    <VmIcon name="home" />
                    {{ t('nav.dashboard') }}
                </Link>

                <div v-for="group in groups" :key="group.id" class="vm-mobilenav__group" :data-testid="`nav-${group.id}`">
                    <button
                        type="button"
                        class="vm-mobilenav__head"
                        :data-testid="`nav-group-${group.id}`"
                        :aria-expanded="expanded.includes(group.id)"
                        :aria-controls="`nav-mobile-${group.id}`"
                        @click="toggleGroup(group.id)"
                    >
                        <VmIcon :name="group.icon" />
                        <span class="vm-mobilenav__label">{{ group.label }}</span>
                        <VmIcon name="chevron" class="vm-toplink__chevron" :class="{ 'vm-toplink__chevron--open': expanded.includes(group.id) }" />
                    </button>

                    <div v-show="expanded.includes(group.id)" :id="`nav-mobile-${group.id}`" class="vm-mobilenav__panel">
                        <VmNavPanel :items="group.items" :active-href="activeHref" />
                    </div>
                </div>

                <VmLocalePicker variant="inline" />
            </nav>
        </header>

        <main id="main" class="vm-shell__main">
            <!--
              A TÁMOGATÓI NÉZET SÁVJA MINDIG LÁTSZIK, amíg a munkamenet nyitva van. Enélkül
              az admin a saját fiókjának hinné a képernyőt, az írás-tiltást pedig hibának.
            -->
            <p
                v-if="page.props.impersonation?.active"
                class="vm-banner vm-banner--danger"
                role="status"
                data-testid="impersonation-banner"
            >
                {{ t('shell.impersonation', {
                    workspace: page.props.impersonation.workspace,
                    expires: format.time(page.props.impersonation.expiresAt),
                }) }}
            </p>

            <p v-if="page.props.flash.success" class="vm-banner" role="status" data-testid="flash-success">
                {{ page.props.flash.success }}
            </p>

            <p v-if="page.props.flash.error" class="vm-banner vm-banner--danger" role="status" data-testid="flash-error">
                {{ page.props.flash.error }}
            </p>

            <slot />
        </main>
    </div>
</template>
