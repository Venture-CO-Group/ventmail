<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    VtAppShell,
    VtBadge,
    VtBanner,
    VtCommandPalette,
    VtNavGroup,
    VtNavItem,
    VtSidebar,
    VtTopbar,
    type VtBadgeTone,
    type VtCommandGroup,
    type VtCommandItem,
    type VtIconName,
} from '@venture-co-group/ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmLocaleMenu from '../Components/Shell/VmLocaleMenu.vue';
import VmUserMenu from '../Components/Shell/VmUserMenu.vue';
import VmWorkspaceMenu from '../Components/Shell/VmWorkspaceMenu.vue';
import { useLocaleSync } from '../composables/useLocaleSync';
import { activeMenuId, closeAllMenus, setMenuOpen } from '../composables/useMenuState';
import { useNavigation, type NavGroupId } from '../composables/useNavigation';
import { readStoredList, writeStoredList } from '../composables/useStoredState';
import { useFormat } from '../composables/useFormat';

/**
 * AZ APP-HÉJ — Unified UI v2 (Figma „Venture Apps — Unified UI v2", AppShell 65:379).
 *
 * Egy keret a családban (1. szabály): `VtAppShell` = 248 px `VtSidebar` (app-ikon + név,
 * appváltó → ventHub, workspace-váltó, csoportosított `VtNavItem`-lista, felhasználó) +
 * 60 px `VtTopbar` (breadcrumb, ⌘K, környezetjelző, nyelv, avatar) + a ventMail-színű
 * GlowLine. Felső legördülő navigáció NINCS. 1024 px alatt a sidebar fiók (a shell adja).
 *
 * SZERKESZTŐ MÓD (`editorMode`): a sablonszerkesztő és a journey-vászon a teljes szélességet
 * kéri — a sidebar ilyenkor csak a menügombbal nyíló fiók (a shell `collapseBelow`-ja
 * gyakorlatilag végtelen), a felső sáv marad.
 *
 * A menü a KÉPESSÉGEKET követi (lásd `useNavigation`): KÉNYELEM, nem védelem.
 */
interface SharedProps {
    [key: string]: unknown;
    editorMode?: boolean;
    impersonation: { active: boolean; workspace: string; expiresAt: string } | null;
    flash: { success: string | null; error: string | null };
    auth: { user: { name: string; email: string } | null };
    workspace: { uuid: string; name: string } | null;
    workspaces: { uuid: string; name: string; roles?: string[] }[];
    sendingMode?: { live: boolean; allowlistEnforced: boolean; marketingEnabled: boolean };
    hubUrl?: string | null;
    locale: string;
}

const props = withDefaults(defineProps<{ editorMode?: boolean }>(), { editorMode: false });

const page = usePage<SharedProps>();
const isEditor = computed(() => props.editorMode || page.props.editorMode === true);
const { t } = useI18n({ useScope: 'global' });
const format = useFormat();
const { groups, activeHref, isOverviewActive } = useNavigation();

useLocaleSync();

/** ventMail navigációs ikonnév → Figma-ikon (02 · Components › icon/*). */
const ICON: Record<string, VtIconName> = {
    users: 'users', filter: 'filter', 'file-text': 'file', layout: 'layout', send: 'send', route: 'branch',
    chart: 'chart', globe: 'globe', key: 'key', server: 'server', shield: 'shield', webhook: 'webhook',
    'file-code': 'code', network: 'layers', activity: 'activity', list: 'list', 'credit-card': 'card',
    inbox: 'inbox', 'check-circle': 'check', palette: 'image', user: 'user', search: 'search',
    gauge: 'gauge', terminal: 'terminal', building: 'building', database: 'database', home: 'home',
};
const iconOf = (name: string): VtIconName => ICON[name] ?? 'grid';

// A csoportok nyitva-állapota a localStorage-ban marad (try/catch a `useStoredState`-ben);
// az aktív pontot tartalmazó csoport mindig nyitva van.
const STORAGE_KEY = 'vm.nav.expanded';
const expanded = ref<string[]>([]);

function groupActive(id: NavGroupId): boolean {
    return groups.value.find((group) => group.id === id)?.items.some((item) => item.href === activeHref.value) ?? false;
}
function setGroupOpen(id: NavGroupId, open: boolean): void {
    expanded.value = open ? [...new Set([...expanded.value, id])] : expanded.value.filter((item) => item !== id);
    writeStoredList(STORAGE_KEY, expanded.value);
}

// Breadcrumb: a csoport (vagy az app) / az oldal.
const crumbs = computed(() => {
    if (isOverviewActive.value) return { section: 'ventMail', page: t('nav.dashboard') };
    for (const group of groups.value) {
        const item = group.items.find((entry) => entry.href === activeHref.value);
        if (item) return { section: group.label, page: item.label };
    }
    return { section: 'ventMail', page: '' };
});

// Környezetjelző (Foundations: egy hely, egy komponens, tónus a kockázat szerint).
const env = computed<{ label: string; tone: VtBadgeTone } | null>(() => {
    const mode = page.props.sendingMode;
    if (!mode) return null;
    const restricted = !mode.live || mode.allowlistEnforced || !mode.marketingEnabled;

    return restricted ? { label: t('shell.env.restricted'), tone: 'warning' } : { label: t('shell.env.live'), tone: 'success' };
});

const role = computed(() => {
    const current = page.props.workspaces?.find((row) => row.uuid === page.props.workspace?.uuid);
    const order = ['owner', 'admin', 'developer', 'editor', 'author', 'analyst'];
    const primary = order.find((name) => current?.roles?.includes(name)) ?? current?.roles?.[0];

    return primary && order.includes(primary) ? t(`shell.role.${primary}`) : (primary ?? '');
});

// ⌘K: ugrás bármely menüpontra (a közös `VtCommandPalette`, saját ⌘K-figyelővel).
const paletteOpen = ref(false);
const query = ref('');
const paletteGroups = computed<VtCommandGroup[]>(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    const matches = (item: VtCommandItem) =>
        needle === '' || item.label.toLocaleLowerCase().includes(needle) || (item.description ?? '').toLocaleLowerCase().includes(needle);
    const all: VtCommandGroup[] = [
        { key: 'overview', label: 'ventMail', items: [{ id: '/dashboard', label: t('nav.dashboard') }] },
        ...groups.value.map((group) => ({
            key: group.id,
            label: group.label,
            items: group.items.map((item) => ({ id: item.href, label: item.label, description: item.description })),
        })),
    ];

    return all.map((group) => ({ ...group, items: group.items.filter(matches) })).filter((group) => group.items.length > 0);
});
function jump(item: VtCommandItem): void {
    paletteOpen.value = false;
    router.visit(item.id);
}

let stopNavigate: (() => void) | undefined;
onMounted(() => {
    expanded.value = readStoredList(STORAGE_KEY);
    // Egy navigáció után egyetlen legördülő sem maradhat nyitva.
    stopNavigate = router.on('navigate', closeAllMenus);
});
onBeforeUnmount(() => stopNavigate?.());

const isMenuOpen = (id: string) => activeMenuId.value === id;
</script>

<template>
    <VtAppShell
        app="mail"
        :main-label="crumbs.page || t('nav.main')"
        :collapse-below="isEditor ? 100000 : 1024"
        :skip-label="t('shell.skipToContent')"
        :data-editor-mode="isEditor ? 'true' : undefined"
    >
        <template #sidebar>
            <VtSidebar
                app="mail"
                :app-subtitle="t('shell.appSubtitle')"
                :hub-href="page.props.hubUrl ?? undefined"
                :hub-label="t('shell.allApps')"
                :workspace="page.props.workspace?.name"
                :workspace-plan="t('shell.workspace')"
                :workspace-menu-label="t('shell.workspaceSwitcher')"
                :workspace-menu-open="isMenuOpen('workspace')"
                :workspace-button-attrs="{ 'data-testid': 'workspace-switch' }"
                :user-name="page.props.auth.user?.name"
                :user-role="role"
                :sign-out-label="t('shell.logout')"
                :nav-label="t('nav.main')"
                data-testid="app-nav"
                @update:workspace-menu-open="setMenuOpen('workspace', $event)"
                @signout="router.post('/auth/logout')"
            >
                <template #workspace-menu="{ close }">
                    <VmWorkspaceMenu :close="close" />
                </template>

                <VtNavItem
                    :as="Link"
                    href="/dashboard"
                    icon="home"
                    :label="t('nav.dashboard')"
                    :active="isOverviewActive"
                    data-testid="nav-overview"
                />

                <VtNavGroup
                    v-for="group in groups"
                    :key="group.id"
                    :label="group.label"
                    :open="groupActive(group.id) || expanded.includes(group.id)"
                    :data-testid="`nav-${group.id}`"
                    @update:open="setGroupOpen(group.id, $event)"
                >
                    <VtNavItem
                        v-for="item in group.items"
                        :key="item.href"
                        :as="Link"
                        :href="item.href"
                        :icon="iconOf(item.icon)"
                        :label="item.label"
                        :active="item.href === activeHref"
                        :data-testid="item.testid"
                    >
                        <template v-if="item.soon" #trailing><VtBadge tone="accent" :dot="false">{{ t('nav.soon') }}</VtBadge></template>
                    </VtNavItem>
                </VtNavGroup>
            </VtSidebar>
        </template>

        <template #topbar>
            <VtTopbar
                :section="crumbs.section"
                :page="crumbs.page"
                :env="env?.label"
                :env-tone="env?.tone"
                :search-placeholder="t('shell.search')"
                :shortcut="false"
                :locale="page.props.locale?.toUpperCase()"
                :locale-menu-label="t('shell.languageMenu')"
                :locale-menu-open="isMenuOpen('locale')"
                :locale-button-attrs="{ 'data-testid': 'locale-switch' }"
                :user-name="page.props.auth.user?.name"
                :user-menu-label="t('shell.userMenu')"
                :user-menu-open="isMenuOpen('user')"
                :avatar-button-attrs="{ 'data-testid': 'user-menu' }"
                :menu-label="t('nav.openMenu')"
                @search="paletteOpen = true"
                @update:locale-menu-open="setMenuOpen('locale', $event)"
                @update:user-menu-open="setMenuOpen('user', $event)"
            >
                <!-- A ventMailnek nincs értesítés-funkciója: a csengő nem kerül ki (HUMAN-QUEUE). -->
                <template #actions><span hidden /></template>
                <template #locale-menu><VmLocaleMenu /></template>
                <template #user-menu><VmUserMenu /></template>
            </VtTopbar>
        </template>

        <!--
          A TÁMOGATÓI NÉZET SÁVJA MINDIG LÁTSZIK, amíg a munkamenet nyitva van. Enélkül
          az admin a saját fiókjának hinné a képernyőt, az írás-tiltást pedig hibának.
        -->
        <VtBanner
            v-if="page.props.impersonation?.active"
            tone="danger"
            :title="t('shell.impersonation', {
                workspace: page.props.impersonation.workspace,
                expires: format.time(page.props.impersonation.expiresAt),
            })"
            data-testid="impersonation-banner"
        />
        <VtBanner v-if="page.props.flash.success" tone="success" :title="page.props.flash.success" data-testid="flash-success" />
        <VtBanner v-if="page.props.flash.error" tone="danger" :title="page.props.flash.error" data-testid="flash-error" />

        <!-- Az oldal burka: `display: contents` (layout.css) — a shell gapje a gyerekekre hat. -->
        <div class="vm-page"><slot /></div>

        <VtCommandPalette
            v-model:open="paletteOpen"
            :groups="paletteGroups"
            :title="t('shell.search')"
            :placeholder="t('shell.search')"
            :empty-text="t('shell.searchEmpty')"
            @query="query = $event"
            @select="jump"
        />
    </VtAppShell>
</template>
