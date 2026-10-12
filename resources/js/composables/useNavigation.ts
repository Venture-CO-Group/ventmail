import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * A FELSŐ NAVIGÁCIÓ adatmodellje: csoportok, menüpontok, képesség-szűrés.
 *
 * A MENÜ A KÉPESSÉGEKET KÖVETI, nem a szerepeket: a szerver küldi a feloldott `abilities`
 * listát, és ami nincs benne, az meg sem jelenik. Ez KÉNYELEM, nem védelem — a kaput minden
 * kontroller `Gate::authorize()`-zal húzza meg, mert egy elrejtett menüpont URL-jét bárki
 * beírhatja.
 *
 * Minden menüpont `testid`-t kap: a felirat NYELVFÜGGŐ, tehát egy szövegre illesztő
 * böngésző-teszt a nyelvváltás után elbukna — pont a nyelvváltást akarjuk mérni.
 */
export type NavGroupId = 'studio' | 'platform' | 'settings' | 'console';

export interface NavItem {
    href: string;
    testid: string;
    label: string;
    description: string;
    icon: string;
    /** „hamarosan" jelzés (D15): az oldal elérhető marad, a jelzés csak a várakozást mondja el. */
    soon?: boolean;
}

export interface NavGroup {
    id: NavGroupId;
    label: string;
    icon: string;
    items: NavItem[];
}

interface NavSharedProps {
    [key: string]: unknown;
    abilities: string[];
    auth: { user: { platformAdmin: boolean } | null };
}

interface RawItem {
    href: string;
    testid: string;
    key: string;
    icon: string;
    show: boolean;
    soon?: boolean;
    labelKey?: string;
}

export function useNavigation(): {
    groups: ComputedRef<NavGroup[]>;
    activeHref: ComputedRef<string | null>;
    isOverviewActive: ComputedRef<boolean>;
} {
    const page = usePage<NavSharedProps>();
    const { t } = useI18n({ useScope: 'global' });

    const can = (ability: string): boolean => (page.props.abilities ?? []).includes(ability);

    const build = (raw: RawItem[]): NavItem[] =>
        raw
            .filter((item) => item.show)
            .map((item) => ({
                href: item.href,
                testid: item.testid,
                label: t(item.labelKey ?? `nav.${item.key}`),
                description: t(`nav.desc.${item.key}`),
                icon: item.icon,
                soon: item.soon,
            }));

    const groups = computed<NavGroup[]>(() => {
        const studio = build([
            { href: '/audience', testid: 'nav-audience', key: 'audience', icon: 'users', show: can('audience.manage') },
            { href: '/forms', testid: 'nav-forms', key: 'forms', icon: 'file-text', show: can('audience.manage') },
            { href: '/templates', testid: 'nav-templates', key: 'templates', icon: 'layout', show: can('template.create') },
            { href: '/campaigns', testid: 'nav-campaigns', key: 'campaigns', icon: 'send', show: can('campaign.create') },
            { href: '/journeys', testid: 'nav-journeys', key: 'journeys', icon: 'route', show: can('campaign.create') },
            { href: '/analytics', testid: 'nav-analytics', key: 'analytics', icon: 'chart', show: can('analytics.view') },
        ]);

        const platform = build([
            { href: '/domains', testid: 'nav-domains', key: 'domains', icon: 'globe', show: can('domain.manage') },
            { href: '/api-keys', testid: 'nav-api-keys', key: 'apiKeys', icon: 'key', show: can('apiKey.manage') },
            { href: '/smtp-credentials', testid: 'nav-smtp', key: 'smtp', icon: 'server', show: can('smtpCredential.manage') },
            { href: '/suppressions', testid: 'nav-suppressions', key: 'suppressions', icon: 'shield', show: can('suppression.manage') },
            { href: '/webhooks', testid: 'nav-webhooks', key: 'webhooks', icon: 'webhook', show: can('webhook.manage') },
            { href: '/stored-templates', testid: 'nav-stored-templates', key: 'storedTemplates', icon: 'file-code', show: can('storedTemplate.manage') },
            { href: '/ip-pools', testid: 'nav-ip-pools', key: 'ipPools', icon: 'network', show: can('ipPool.manage') },
            { href: '/deliverability', testid: 'nav-deliverability', key: 'deliverability', icon: 'activity', show: can('analytics.view') },
            { href: '/logs', testid: 'nav-logs', key: 'logs', icon: 'list', show: can('logs.view') },
            { href: '/logs/statistics', testid: 'nav-statistics', key: 'statistics', icon: 'chart', show: can('logs.view') },
            { href: '/billing', testid: 'nav-billing', key: 'billing', icon: 'credit-card', show: can('billing.manage') },
            // D15: „hamarosan" jelzés — a meglévő képernyők a régi útvonalon elérhetők maradnak.
            { href: '/inbound', testid: 'nav-inbound', key: 'inbound', icon: 'inbox', show: can('domain.manage'), soon: true },
            {
                href: '/validation',
                testid: 'nav-validation',
                key: 'validation',
                labelKey: 'nav.validationApi',
                icon: 'check-circle',
                show: can('validation.run'),
                soon: true,
            },
        ]);

        /*
         | BEÁLLÍTÁSOK: ami a WORKSPACE-ről és a FIÓKRÓL szól, nem a küldésről. A profil
         | mindenkinek elérhető (saját beállítás, nincs hozzá képesség).
         */
        const settings = build([
            { href: '/members', testid: 'nav-members', key: 'members', icon: 'users', show: can('member.manage') },
            { href: '/profile', testid: 'nav-profile', key: 'profile', labelKey: 'nav.profile', icon: 'user', show: true },
        ]);

        // A PLATFORM-KONZOL csak platform-adminnak látszik. KÉNYELEM, nem védelem: a kaput a
        // `platform.admin` képesség adja, minden művelet előtt.
        const console_ = page.props.auth?.user?.platformAdmin
            ? [
                  {
                      href: '/platform',
                      testid: 'nav-platform-console',
                      label: t('platform.title'),
                      description: t('nav.desc.console'),
                      icon: 'terminal',
                  },
                  {
                      href: '/platform/audit',
                      testid: 'nav-platform-audit',
                      label: t('platform.auditSearch'),
                      description: t('nav.desc.audit'),
                      icon: 'search',
                  },
              ]
            : [];

        const result: NavGroup[] = [
            { id: 'studio', label: t('nav.studio'), icon: 'megaphone', items: studio },
            { id: 'platform', label: t('nav.platform'), icon: 'code', items: platform },
            { id: 'settings', label: t('nav.settings'), icon: 'sliders', items: settings },
            { id: 'console', label: t('nav.console'), icon: 'terminal', items: console_ },
        ];

        return result.filter((group) => group.items.length > 0);
    });

    /*
     | AZ AKTÍV MENÜPONT = a LEGHOSSZABB illeszkedő útvonal. Prefix-egyezéssel a `/logs` a
     | `/logs/statistics` oldalon is aktív lenne — két aktív pont egyszerre pont az, amit a
     | `aria-current` nem engedhet.
     */
    const activeHref = computed<string | null>(() => {
        const path = page.url.split('?')[0].split('#')[0];
        const hrefs = groups.value.flatMap((group) => group.items.map((item) => item.href));
        const matches = hrefs.filter((href) => path === href || path.startsWith(`${href}/`));

        return matches.sort((a, b) => b.length - a.length)[0] ?? null;
    });

    const isOverviewActive = computed(() => {
        const path = page.url.split('?')[0];

        return path === '/dashboard' || path.startsWith('/dashboard/');
    });

    return { groups, activeHref, isOverviewActive };
}
