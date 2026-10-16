<script setup lang="ts">
/*
 | FIÓK-MENÜ — a TARTALOM (a gomb a `VtTopbar` avatarja, `user-menu` slot): név és e-mail, Profilbeállítások, Fiók és biztonság (ventSSO, új
 | lapon), Kijelentkezés (RP-initiated logout: a ventSSO munkamenete is záródik), és alul,
 | visszafogottan, a futó verzió.
 |
 | A kép: a ventSSO `picture` claimet a ventMail ma nem tárolja, ezért az avatar MONOGRAM
 | (a `VtAvatar` a névből képzi). Ha a tárolás megérkezik, az `avatarUrl` prop már kész.
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtAvatar, VtDropdownItem } from '@venture-co-group/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from './VmIcon.vue';
import VmLocaleMenu from './VmLocaleMenu.vue';

defineProps<{ avatarUrl?: string }>();

const page = usePage<{
    auth: { user: { name: string; email: string } | null };
    app: { version: string };
    ssoAccountUrl: string;
}>();
const { t } = useI18n({ useScope: 'global' });

const user = computed(() => page.props.auth.user);
// A kiadás-címke `1.2.0` (metadata-action) vagy `v1.2.0` lehet: a megjelenítés mindig `v`-s.
const version = computed(() => {
    const raw = page.props.app?.version ?? '';

    return /^\d/.test(raw) ? `v${raw}` : raw;
});

function logout(): void {
    // RP-initiated logout: a `LogoutController` a ventSSO end-session végpontjára irányít.
    router.post('/auth/logout');
}
</script>

<template>
    <div class="vm-usermenu">
        <div class="vm-usermenu__head" role="presentation">
            <VtAvatar :name="user?.name ?? ''" :src="avatarUrl" size="lg" />
            <div class="vm-usermenu__who">
                <span class="vm-usermenu__name" data-testid="user-name">{{ user?.name }}</span>
                <span class="vm-usermenu__email" data-testid="user-email">{{ user?.email }}</span>
            </div>
        </div>

        <VtDropdownItem :as="Link" href="/profile" role="menuitem" data-testid="user-profile">
            <template #icon><VmIcon name="user" /></template>
            {{ t('shell.profile') }}
        </VtDropdownItem>

        <VtDropdownItem :href="page.props.ssoAccountUrl" role="menuitem" target="_blank" rel="noopener noreferrer" data-testid="user-account">
            <template #icon><VmIcon name="shield" /></template>
            {{ t('shell.account') }}
            <span class="vm-visually-hidden">({{ t('shell.opensNewTab') }})</span>
            <template #trailing><VmIcon name="external" /></template>
        </VtDropdownItem>

        <VtDropdownItem role="menuitem" data-testid="logout" @select="logout">
            <template #icon><VmIcon name="logout" /></template>
            {{ t('shell.logout') }}
        </VtDropdownItem>

        <!-- Keskeny képernyőn a felső sáv elrejti a nyelvválasztót: itt mindig elérhető. -->
        <VmLocaleMenu variant="inline" />

        <p v-if="version" class="vm-usermenu__version vt-mono" data-testid="app-version">
            {{ t('shell.version', { version }) }}
        </p>
    </div>
</template>
