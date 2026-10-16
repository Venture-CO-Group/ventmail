<?php

declare(strict_types=1);

/*
 | UNIFIED UI v2 · AZ APP-HÉJ forrás-szerződései, amiket böngésző nélkül is lehet őrizni (a
 | viselkedést az `e2e/shell-nav.spec.ts` méri). A keret a @venture-co-group/ui-ból jön (1. szabály):
 | VtAppShell + VtSidebar + VtTopbar, felső legördülő navigáció nincs.
 */

function shellSource(string $path): string
{
    return file_get_contents(resource_path('js/'.$path));
}

it('az app-héj a közös AppShell: sidebar + felső sáv, felső navigáció nincs, és kér szerkesztő módot', function () {
    $layout = shellSource('Layouts/AppLayout.vue');

    expect($layout)->toContain('<VtAppShell')->toContain('app="mail"')
        ->toContain('<VtSidebar')->toContain('<VtTopbar')->toContain('<VtNavGroup')->toContain('<VtNavItem')
        ->and($layout)->not->toContain('vm-topbar')
        ->and($layout)->not->toContain('nav-hamburger')
        // A szerkesztő mód: prop VAGY a kontroller `editorMode` Inertia-propja — a sidebar ilyenkor fiók.
        ->and($layout)->toContain('page.props.editorMode === true')
        ->and($layout)->toContain(':collapse-below="isEditor ? 100000 : 1024"');

    // A régi helyi héj-darabok kivezetve.
    foreach (['shell.css', 'nav.css', 'Components/Shell/VmNavPanel.vue', 'Components/Shell/VmWorkspaceSwitcher.vue', 'Components/Shell/VmLocalePicker.vue'] as $gone) {
        expect(file_exists(resource_path('js/'.$gone)))->toBeFalse();
    }
});

it('minden meglévő menüpont megmarad a testid-jával, képesség-szűréssel', function () {
    $nav = shellSource('composables/useNavigation.ts');

    $expected = [
        'nav-audience' => 'audience.manage', 'nav-forms' => 'audience.manage', 'nav-templates' => 'template.create',
        'nav-campaigns' => 'campaign.create', 'nav-journeys' => 'campaign.create', 'nav-analytics' => 'analytics.view',
        'nav-domains' => 'domain.manage', 'nav-api-keys' => 'apiKey.manage', 'nav-smtp' => 'smtpCredential.manage',
        'nav-suppressions' => 'suppression.manage', 'nav-webhooks' => 'webhook.manage',
        'nav-stored-templates' => 'storedTemplate.manage', 'nav-ip-pools' => 'ipPool.manage',
        'nav-validation' => 'validation.run', 'nav-deliverability' => 'analytics.view', 'nav-logs' => 'logs.view',
        'nav-statistics' => 'logs.view', 'nav-inbound' => 'domain.manage', 'nav-billing' => 'billing.manage',
        'nav-members' => 'member.manage', 'nav-platform-console' => 'platformAdmin', 'nav-platform-audit' => 'platformAdmin',
    ];

    foreach ($expected as $testid => $ability) {
        expect($nav)->toContain("'{$testid}'");

        if ($ability !== 'platformAdmin') {
            expect($nav)->toContain("can('{$ability}')");
        }
    }

    // D15: „hamarosan" jelzés az Inbound-on és a Validation API-n.
    expect($nav)->toMatch("/testid: 'nav-inbound'.*soon: true/s")
        ->and($nav)->toMatch("/testid: 'nav-validation'.*soon: true/s");
});

it('a héj menüi a könyvtár slotjaiban élnek (ARIA, Esc, fókusz a könyvtárban), lokális legördülő nincs', function () {
    expect(file_exists(resource_path('js/Components/Shell/VmDropdown.vue')))->toBeFalse();

    $layout = shellSource('Layouts/AppLayout.vue');

    // A trigger-gombot és a panelt a VtSidebar / VtTopbar adja; az app csak a tartalmat.
    expect($layout)->toContain('#workspace-menu')->toContain('#locale-menu')->toContain('#user-menu')
        // Egyszerre egy nyitott: vezérelt mód, közös állapot.
        ->toContain(':workspace-menu-open="isMenuOpen(\'workspace\')"')
        ->toContain(':locale-menu-open="isMenuOpen(\'locale\')"')
        ->toContain(':user-menu-open="isMenuOpen(\'user\')"');

    expect(shellSource('Components/Shell/VmUserMenu.vue'))->toContain('role="menuitem"')
        ->and(shellSource('Components/Shell/VmLocaleMenu.vue'))->toContain('role="menuitemradio"');

    // A tartalmi legördülők továbbra is a könyvtár VtDropdown-ja.
    $export = shellSource('Components/Charts/VmChartExportMenu.vue');

    expect($export)->toContain('<VtDropdown')->toContain('mode="menu"')->toContain('v-bind="trigger"')->toMatch('/:open="/');
});

it('a localStorage minden elérése try/catch-ben van, és a töréspont nem beégetett', function () {
    $store = shellSource('composables/useStoredState.ts');

    // Két elérés (olvasás, írás) — mindkettő try/catch-ben.
    expect(preg_match_all('/window\.localStorage\./', $store))->toBe(2)
        ->and(preg_match_all('/\btry\s*\{/', $store))->toBe(2)
        ->and(preg_match_all('/\}\s*catch\b/', $store))->toBe(2);

    // A töréspont a spacing-tokenből számolt érték, nem nyers hossz és nem media query.
    $compact = shellSource('composables/useCompactNav.ts');

    expect($compact)->toContain('calc(calc(var(--vt-space-8) * 4) * ${BREAKPOINT_FACTOR})')
        ->and($compact)->not->toContain('matchMedia');
});

it('az avatar-menü a ventSSO fiókot új lapon nyitja, és az RP-logoutot hívja', function () {
    $menu = shellSource('Components/Shell/VmUserMenu.vue');

    expect($menu)->toContain('target="_blank"')
        ->and($menu)->toContain('noopener noreferrer')
        ->and($menu)->toContain('ssoAccountUrl')
        ->and($menu)->toContain("router.post('/auth/logout')")
        ->and($menu)->toContain('href="/profile"');
});
