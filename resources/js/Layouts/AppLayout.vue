<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { VtGlowLine, VtSelect } from '@venture/ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { loadLocaleMessages } from '../i18n';

/**
 * AZ APP-HÉJ: navigáció, workspace-váltó, nyelvválasztó, támogatói-nézet sáv.
 *
 * A MENÜ A KÉPESSÉGEKET KÖVETI, nem a szerepeket: a szerver küldi a feloldott
 * `abilities` listát, és ami nincs benne, az meg sem jelenik. Ez KÉNYELEM, nem védelem —
 * a kaput minden kontroller `Gate::authorize()`-zal húzza meg, mert egy elrejtett
 * menüpont URL-jét bárki beírhatja.
 */
interface SharedProps {
    [key: string]: unknown;
    auth: { user: { name: string; email: string; platformAdmin: boolean } | null };
    workspace: { uuid: string; name: string } | null;
    workspaces: Array<{ uuid: string; name: string }>;
    abilities: string[];
    impersonation: { active: boolean; workspace: string; expiresAt: string } | null;
    locale: string;
    supportedLocales: string[];
    flash: { success: string | null; error: string | null };
}

const page = usePage<SharedProps>();
const { t, locale, setLocaleMessage } = useI18n({ useScope: 'global' });

const abilities = computed(() => page.props.abilities ?? []);
const can = (ability: string): boolean => abilities.value.includes(ability);

/*
 | A MENÜPONTOK `testid`-t is kapnak. Nem kényelmi okból: a felirat NYELVFÜGGŐ, tehát egy
 | szövegre illesztő böngésző-teszt a nyelvváltás után elbukna — és pont a nyelvváltást
 | akarjuk mérni.
 */
const platform = computed(() =>
    [
        { href: '/domains', id: 'nav-domains', label: t('nav.domains'), show: can('domain.manage') },
        { href: '/api-keys', id: 'nav-api-keys', label: t('nav.apiKeys'), show: can('apiKey.manage') },
        { href: '/smtp-credentials', id: 'nav-smtp', label: t('nav.smtp'), show: can('smtpCredential.manage') },
        { href: '/suppressions', id: 'nav-suppressions', label: t('nav.suppressions'), show: can('suppression.manage') },
        { href: '/webhooks', id: 'nav-webhooks', label: t('nav.webhooks'), show: can('webhook.manage') },
        { href: '/ip-pools', id: 'nav-ip-pools', label: t('nav.ipPools'), show: can('ipPool.manage') },
        { href: '/validation', id: 'nav-validation', label: t('nav.validation'), show: can('validation.run') },
        { href: '/deliverability', id: 'nav-deliverability', label: t('nav.deliverability'), show: can('analytics.view') },
        { href: '/logs', id: 'nav-logs', label: t('nav.logs'), show: can('logs.view') },
        { href: '/logs/statistics', id: 'nav-statistics', label: t('nav.statistics'), show: can('logs.view') },
        { href: '/inbound', id: 'nav-inbound', label: t('nav.inbound'), show: can('domain.manage') },
        { href: '/billing', id: 'nav-billing', label: t('nav.billing'), show: can('billing.manage') },
    ].filter((item) => item.show),
);

/*
 | A STUDIO külön menü-csoport: a Mailchimp-oldal más munkakör (marketinges), mint a
 | platform-oldal (fejlesztő, üzemeltető). Egy közös listában a kettő elfedné egymást.
 */
const studio = computed(() =>
    [
        { href: '/audience', id: 'nav-audience', label: t('nav.audience'), show: can('audience.manage') },
        { href: '/templates', id: 'nav-templates', label: t('nav.templates'), show: can('template.create') },
        { href: '/campaigns', id: 'nav-campaigns', label: t('nav.campaigns'), show: can('campaign.create') },
        { href: '/journeys', id: 'nav-journeys', label: t('nav.journeys'), show: can('campaign.create') },
        { href: '/analytics', id: 'nav-analytics', label: t('nav.analytics'), show: can('analytics.view') },
    ].filter((item) => item.show),
);

const workspaceOptions = computed(() =>
    (page.props.workspaces ?? []).map((workspace) => ({ value: workspace.uuid, label: workspace.name })),
);

const localeOptions = computed(() =>
    (page.props.supportedLocales ?? []).map((code) => ({ value: code, label: code.toUpperCase() })),
);

function switchWorkspace(uuid: string): void {
    if (!uuid || uuid === page.props.workspace?.uuid) return;

    router.post('/workspace/switch', { workspace: uuid }, { preserveScroll: true });
}

async function switchLocale(code: string): Promise<void> {
    if (!code || code === page.props.locale) return;

    /*
     | ELŐBB A SZÓTÁR, aztán a váltás.
     |
     | A bundle-ök lusták (nyelvenként külön chunk), tehát a kiválasztott nyelv szótára a
     | boot óta NINCS a memóriában. Ha csak a `locale`-t állítanánk át, a felület a
     | fallback nyelvre esne vissza — mérve: magyarról németre váltva ANGOL felirat jött,
     | és a felhasználó azt hinné, elromlott a nyelvváltó.
     */
    setLocaleMessage(code, (await loadLocaleMessages(code)) as never);
    locale.value = code;

    // A NYELV a felhasználó sorára megy (nem session-be), ezért szerver-hívás is kell: a
    // szerver-oldali szövegek (gate-üzenetek, flash) különben a következő kérésnél megint
    // a régi nyelven jönnének.
    router.post('/locale', { locale: code }, { preserveScroll: true });
}
</script>

<template>
    <div class="vm-shell">
        <a class="vm-visually-hidden" href="#main">{{ t('shell.skipToContent') }}</a>

        <nav class="vm-shell__nav" data-testid="app-nav" :aria-label="t('nav.platform')">
            <div>
                <strong class="vt-text-display">ventMail</strong>
                <VtGlowLine />
            </div>

            <div class="vm-nav-group">
                <Link class="vm-nav-link" href="/dashboard" :aria-current="$page.url.startsWith('/dashboard') ? 'page' : undefined">
                    {{ t('nav.dashboard') }}
                </Link>
            </div>

            <div v-if="platform.length" class="vm-nav-group" data-testid="nav-platform">
                <span class="vm-nav-group__title vt-eyebrow">{{ t('nav.platform') }}</span>
                <Link
                    v-for="item in platform"
                    :key="item.href"
                    class="vm-nav-link"
                    :href="item.href"
                    :data-testid="item.id"
                    :aria-current="$page.url.startsWith(item.href) ? 'page' : undefined"
                >
                    {{ item.label }}
                </Link>
            </div>

            <div v-if="studio.length" class="vm-nav-group" data-testid="nav-studio">
                <span class="vm-nav-group__title vt-eyebrow">{{ t('nav.studio') }}</span>
                <Link
                    v-for="item in studio"
                    :key="item.href"
                    class="vm-nav-link"
                    :href="item.href"
                    :data-testid="item.id"
                    :aria-current="$page.url.startsWith(item.href) ? 'page' : undefined"
                >
                    {{ item.label }}
                </Link>
            </div>

            <div v-if="page.props.auth.user?.platformAdmin" class="vm-nav-group" data-testid="nav-console">
                <span class="vm-nav-group__title vt-eyebrow">{{ t('nav.console') }}</span>
            </div>

            <div class="vm-stack">
                <label class="vm-nav-group__title vt-eyebrow" for="workspace-switch">{{ t('shell.workspace') }}</label>
                <VtSelect
                    id="workspace-switch"
                    data-testid="workspace-switch"
                    :model-value="page.props.workspace?.uuid ?? ''"
                    :options="workspaceOptions"
                    @update:model-value="switchWorkspace(String($event))"
                />

                <label class="vm-nav-group__title vt-eyebrow" for="locale-switch">{{ t('shell.language') }}</label>
                <VtSelect
                    id="locale-switch"
                    data-testid="locale-switch"
                    :model-value="page.props.locale"
                    :options="localeOptions"
                    @update:model-value="void switchLocale(String($event))"
                />
            </div>
        </nav>

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
                    expires: new Date(page.props.impersonation.expiresAt).toLocaleTimeString(),
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
