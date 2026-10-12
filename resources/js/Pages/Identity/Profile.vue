<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { VtBadge, VtButton, VtCard, VtCheckbox, VtField, VtSelect } from '@venture/ui';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import VmIcon from '../../Components/Shell/VmIcon.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * PROFILBEÁLLÍTÁSOK — csak a ventMail-specifikus, app-szintű beállítások (D11): nyelv,
 * időzóna, dátum- és számformátum, értesítések, alapértelmezett workspace.
 *
 * A személyes adat, a jelszó és a 2FA a ventSSO-ban él: erre EGY link-kártya mutat, semmi
 * nincs duplikálva. A szerver validál (a kliens csak a hibát mutatja).
 */
interface Settings {
    locale: string;
    timezone: string;
    date_format: string;
    number_format: string;
    notifications: Record<string, boolean>;
    default_workspace: string | null;
}

const props = defineProps<{
    settings: Settings;
    timezones: string[];
    dateFormats: string[];
    numberFormats: string[];
    notificationKeys: string[];
    ssoAccountUrl: string;
}>();

const page = usePage<{
    auth: { user: { name: string; email: string } | null };
    workspaces: Array<{ uuid: string; name: string; roles?: string[] }>;
    supportedLocales: string[];
    errors: Record<string, string>;
}>();
const { t } = useI18n({ useScope: 'global' });

const form = reactive({
    locale: props.settings.locale,
    timezone: props.settings.timezone,
    date_format: props.settings.date_format,
    number_format: props.settings.number_format,
    notifications: { ...props.settings.notifications },
    default_workspace: props.settings.default_workspace ?? '',
});

const saving = ref(false);
const errors = computed(() => page.props.errors ?? {});

// A mintapéldák nyelvfüggetlenek (számok és elválasztók) — nem fordítandó szöveg.
const DATE_SAMPLES: Record<string, string> = {
    ymd: '2026. 10. 12.',
    dmy: '12/10/2026',
    mdy: '10/12/2026',
    iso: '2026-10-12',
};
const NUMBER_SAMPLES: Record<string, string> = {
    space_comma: '1 234 567,89',
    comma_dot: '1,234,567.89',
    dot_comma: '1.234.567,89',
    apostrophe_dot: '1’234’567.89',
};

const localeOptions = computed(() =>
    (page.props.supportedLocales ?? []).map((code) => ({
        value: code,
        label: `${t(`shell.languageName.${code}`)} (${code.toUpperCase()})`,
    })),
);
const timezoneOptions = computed(() => props.timezones.map((zone) => ({ value: zone, label: zone })));
const dateOptions = computed(() => props.dateFormats.map((key) => ({ value: key, label: DATE_SAMPLES[key] ?? key })));
const numberOptions = computed(() => props.numberFormats.map((key) => ({ value: key, label: NUMBER_SAMPLES[key] ?? key })));

const ROLE_ORDER = ['owner', 'admin', 'developer', 'editor', 'author', 'analyst'];

const workspaceOptions = computed(() => [
    { value: '', label: t('profile.defaultWorkspaceNone') },
    ...(page.props.workspaces ?? []).map((row) => ({ value: row.uuid, label: `${row.name} (${roleLabel(row)})` })),
]);

function roleLabel(row: { roles?: string[] }): string {
    const role = ROLE_ORDER.find((candidate) => (row.roles ?? []).includes(candidate)) ?? (row.roles ?? [])[0] ?? '';

    return ROLE_ORDER.includes(role) ? t(`shell.role.${role}`) : role;
}

function toggleNotification(key: string, value: boolean): void {
    form.notifications[key] = value;
}

function save(): void {
    saving.value = true;

    router.put(
        '/profile',
        {
            locale: form.locale,
            timezone: form.timezone,
            date_format: form.date_format,
            number_format: form.number_format,
            notifications: form.notifications,
            default_workspace: form.default_workspace === '' ? null : form.default_workspace,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <AppLayout>
        <header class="vm-pagehead">
            <div class="vm-stack">
                <p class="vt-eyebrow">{{ t('profile.eyebrow') }}</p>
                <h1 class="vt-text-display" data-testid="profile-title">{{ t('profile.title') }}</h1>
                <p>{{ t('profile.lead') }}</p>
            </div>
            <VtButton :disabled="saving" data-testid="profile-save" @click="save">{{ t('profile.save') }}</VtButton>
        </header>

        <p v-if="Object.keys(errors).length" class="vm-banner vm-banner--danger" role="alert" data-testid="profile-errors">
            {{ t('profile.saveFailed') }}
        </p>

        <div class="vm-profile">
            <form class="vm-stack vm-profile__main" novalidate @submit.prevent="save">
                <VtCard>
                    <h2 class="vt-text-display">{{ t('profile.sectionFormats') }}</h2>

                    <div class="vm-profile__grid">
                        <VtField :label="t('profile.language')" :hint="t('profile.languageHint')" :error="errors.locale">
                            <VtSelect v-model="form.locale" :options="localeOptions" data-testid="profile-locale" />
                        </VtField>

                        <VtField :label="t('profile.timezone')" :hint="t('profile.timezoneHint')" :error="errors.timezone">
                            <VtSelect v-model="form.timezone" :options="timezoneOptions" data-testid="profile-timezone" />
                        </VtField>

                        <VtField :label="t('profile.dateFormat')" :error="errors.date_format">
                            <VtSelect v-model="form.date_format" :options="dateOptions" data-testid="profile-date-format" />
                        </VtField>

                        <VtField :label="t('profile.numberFormat')" :error="errors.number_format">
                            <VtSelect v-model="form.number_format" :options="numberOptions" data-testid="profile-number-format" />
                        </VtField>
                    </div>

                    <VtField
                        :label="t('profile.defaultWorkspace')"
                        :hint="t('profile.defaultWorkspaceHint')"
                        :error="errors.default_workspace"
                    >
                        <VtSelect v-model="form.default_workspace" :options="workspaceOptions" data-testid="profile-default-workspace" />
                    </VtField>
                </VtCard>

                <VtCard>
                    <h2 class="vt-text-display">{{ t('profile.sectionNotifications') }}</h2>

                    <ul class="vm-profile__notifs" role="list">
                        <li v-for="key in notificationKeys" :key="key" class="vm-profile__notif">
                            <VtCheckbox
                                :model-value="form.notifications[key]"
                                :data-testid="`profile-notif-${key}`"
                                @update:model-value="toggleNotification(key, $event)"
                            >
                                <span class="vm-profile__notif-label">{{ t(`profile.notifications.${key}.label`) }}</span>
                            </VtCheckbox>
                            <span class="vm-profile__notif-hint">{{ t(`profile.notifications.${key}.hint`) }}</span>
                        </li>
                    </ul>
                </VtCard>
            </form>

            <aside class="vm-stack vm-profile__side">
                <VtCard glow data-testid="profile-sso-card">
                    <div class="vm-profile__who">
                        <div class="vm-stack">
                            <span class="vm-profile__name">{{ page.props.auth.user?.name }}</span>
                            <span class="vm-profile__email">{{ page.props.auth.user?.email }}</span>
                        </div>
                    </div>

                    <h2 class="vt-text-display">{{ t('profile.sso.title') }}</h2>
                    <p>{{ t('profile.sso.text') }}</p>

                    <a
                        class="vm-linkbutton"
                        :href="ssoAccountUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-testid="profile-sso-link"
                    >
                        {{ t('profile.sso.open') }}
                        <VmIcon name="external" />
                        <span class="vm-visually-hidden">({{ t('shell.opensNewTab') }})</span>
                    </a>
                </VtCard>

                <VtCard>
                    <h2 class="vt-text-display">{{ t('profile.rolesTitle') }}</h2>
                    <ul class="vm-profile__roles" role="list">
                        <li v-for="row in page.props.workspaces" :key="row.uuid" class="vm-profile__role">
                            <span class="vm-profile__role-name">{{ row.name }}</span>
                            <VtBadge size="sm">{{ roleLabel(row) }}</VtBadge>
                        </li>
                    </ul>
                </VtCard>
            </aside>
        </div>
    </AppLayout>
</template>
