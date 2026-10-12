<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthShell from '../../Components/AuthShell.vue';

/*
 | A megtagadott belépés: a ventSSO-fiók érvényes, de ventMail-szerepe, tagsága vagy meghívója
 | nincs. A HTTP-státusz 403 marad (a kontraktus); ez az oldal csak érthetővé teszi.
 */
const { t } = useI18n({ useScope: 'global' });
</script>

<template>
    <Head :title="t('auth.denied.title')" />

    <AuthShell>
        <h1 class="auth__title">{{ t('auth.denied.title') }}</h1>
        <p class="auth__lede" role="alert" data-testid="denied-message">{{ t('auth.denied.lede') }}</p>
        <p class="auth__note">{{ t('auth.denied.hint') }}</p>

        <a class="auth__btn auth__btn--ghost" href="/" data-testid="denied-back">{{ t('auth.denied.back') }}</a>
    </AuthShell>
</template>
