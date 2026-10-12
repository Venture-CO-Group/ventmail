<script setup lang="ts">
/*
 | AVATAR + FIÓK-MENÜ: név és e-mail, Profilbeállítások, Fiók és biztonság (ventSSO, új
 | lapon), Kijelentkezés (RP-initiated logout: a ventSSO munkamenete is záródik), és alul,
 | visszafogottan, a futó verzió.
 |
 | A kép: a ventSSO `picture` claimet a ventMail ma nem tárolja, ezért az avatar MONOGRAM
 | (a `VtAvatar` a névből képzi). Ha a tárolás megérkezik, az `avatarUrl` prop már kész.
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtAvatar } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import VmDropdown from './VmDropdown.vue';
import VmIcon from './VmIcon.vue';

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
    <VmDropdown
        id="user"
        mode="menu"
        align="end"
        testid="user-menu"
        :label="t('shell.userMenu')"
        trigger-class="vm-avatarbtn"
        panel-class="vm-usermenu"
    >
        <template #trigger>
            <VtAvatar :name="user?.name ?? ''" :src="avatarUrl" size="md" />
        </template>

        <template #default="{ close }">
            <div class="vm-usermenu__head" role="presentation">
                <VtAvatar :name="user?.name ?? ''" :src="avatarUrl" size="lg" />
                <div class="vm-usermenu__who">
                    <span class="vm-usermenu__name" data-testid="user-name">{{ user?.name }}</span>
                    <span class="vm-usermenu__email" data-testid="user-email">{{ user?.email }}</span>
                </div>
            </div>

            <Link class="vm-menuitem" href="/profile" role="menuitem" data-testid="user-profile" @click="close()">
                <VmIcon name="user" />
                <span class="vm-menuitem__label">{{ t('shell.profile') }}</span>
            </Link>

            <a
                class="vm-menuitem"
                role="menuitem"
                :href="page.props.ssoAccountUrl"
                target="_blank"
                rel="noopener noreferrer"
                data-testid="user-account"
                @click="close()"
            >
                <VmIcon name="shield" />
                <span class="vm-menuitem__label">{{ t('shell.account') }}</span>
                <VmIcon name="external" class="vm-menuitem__check" />
                <span class="vm-visually-hidden">({{ t('shell.opensNewTab') }})</span>
            </a>

            <button type="button" class="vm-menuitem" role="menuitem" data-testid="logout" @click="logout">
                <VmIcon name="logout" />
                <span class="vm-menuitem__label">{{ t('shell.logout') }}</span>
            </button>

            <p v-if="version" class="vm-usermenu__version vt-text-mono" data-testid="app-version">
                {{ t('shell.version', { version }) }}
            </p>
        </template>
    </VmDropdown>
</template>
