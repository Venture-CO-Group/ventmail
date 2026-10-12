<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthShell from '../../Components/AuthShell.vue';

/*
 | A BELÉPŐ. Az identitás gazdája a ventSSO (golden rule 1): itt nincs jelszó-mező, egyetlen
 | gomb visz a ventSSO-hoz. A gomb TELJES NAVIGÁCIÓ (`<a href>`), nem Inertia-link: az
 | `/auth/redirect` külső origin-re (302) irányít, amit az XHR-alapú Inertia-látogatás nem követhet.
 */
interface SharedProps {
    [key: string]: unknown;
    flash: { success: string | null; error: string | null };
}

const page = usePage<SharedProps>();
const { t } = useI18n({ useScope: 'global' });
</script>

<template>
    <Head :title="t('auth.login.title')" />

    <AuthShell>
        <h1 class="auth__title">{{ t('auth.login.title') }}</h1>
        <p class="auth__lede">{{ t('auth.login.lede') }}</p>

        <p v-if="page.props.flash?.error" class="auth__error" role="alert" data-testid="login-error">
            {{ page.props.flash.error }}
        </p>

        <a class="auth__btn" href="/auth/redirect" data-testid="login-sso">{{ t('auth.login.button') }}</a>

        <p class="auth__note">{{ t('auth.login.note') }}</p>
    </AuthShell>
</template>
