<?php

declare(strict_types=1);

/*
 | v1.1 · SHELL — a felső navigáció FORRÁS-szerződései, amiket böngésző nélkül is lehet
 | őrizni (a viselkedést az `e2e/shell-nav.spec.ts` méri).
 */

function shellSource(string $path): string
{
    return file_get_contents(resource_path('js/'.$path));
}

it('az app-héj felső sáv, nem oldalsáv, és kér szerkesztő módot', function () {
    $layout = shellSource('Layouts/AppLayout.vue');

    expect($layout)->toContain('vm-topbar')
        ->and($layout)->not->toContain('vm-shell__nav')
        // A szerkesztő mód: prop VAGY a kontroller `editorMode` Inertia-propja.
        ->and($layout)->toContain('editorMode')
        ->and($layout)->toContain('page.props.editorMode === true')
        // Hamburger tablet alatt, harmonikás csoportokkal.
        ->and($layout)->toContain('nav-hamburger')
        ->and($layout)->toContain('aria-expanded');
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

it('a legördülők a @venture/ui VtDropdown-ra épülnek (ARIA, Esc, fókusz-visszatérés a könyvtárban), lokális pótlás nincs', function () {
    // D315: a lokális legördülő kivezetve — az ARIA-mintát (aria-expanded / aria-controls /
    // aria-haspopup, role="menu"), az Esc-zárást és a fókusz-visszatérést a könyvtár adja (a
    // venture-ui saját tesztjei + e2e/ui-library-dropdown.spec.ts, e2e/shell-nav.spec.ts).
    expect(file_exists(resource_path('js/Components/Shell/VmDropdown.vue')))->toBeFalse();

    $menus = [
        'Layouts/AppLayout.vue' => 'disclosure',
        'Components/Shell/VmWorkspaceSwitcher.vue' => 'disclosure',
        'Components/Shell/VmLocalePicker.vue' => 'menu',
        'Components/Shell/VmUserMenu.vue' => 'menu',
        'Components/Charts/VmChartExportMenu.vue' => 'menu',
    ];

    foreach ($menus as $path => $mode) {
        $source = shellSource($path);

        expect($source)->toContain('<VtDropdown')
            // A könyvtár trigger-attribútumai (aria-expanded, aria-controls, onClick) a saját gombon.
            ->and($source)->toContain('v-bind="trigger"')
            // Egyszerre egy nyitott: vezérelt mód.
            ->and($source)->toMatch('/:open="/');

        if ($mode === 'menu') {
            expect($source)->toContain('mode="menu"')->and($source)->toContain('role="menuitem');
        } else {
            expect($source)->not->toContain('mode="menu"');
        }
    }
});

it('a localStorage minden elérése try/catch-ben van, és a töréspont nem beégetett', function () {
    $store = shellSource('composables/useStoredState.ts');

    // Két elérés (olvasás, írás) — mindkettő try/catch-ben.
    expect(preg_match_all('/window\.localStorage\./', $store))->toBe(2)
        ->and(preg_match_all('/\btry\s*\{/', $store))->toBe(2)
        ->and(preg_match_all('/\}\s*catch\b/', $store))->toBe(2);

    // A töréspont a spacing-tokenből számolt érték, nem nyers hossz és nem media query.
    $compact = shellSource('composables/useCompactNav.ts');

    expect($compact)->toContain('var(--vt-space-32)')
        ->and(shellSource('nav.css'))->not->toContain('@media (max-width')
        ->and(shellSource('nav.css'))->not->toContain('@media (min-width');
});

it('az avatar-menü a ventSSO fiókot új lapon nyitja, és az RP-logoutot hívja', function () {
    $menu = shellSource('Components/Shell/VmUserMenu.vue');

    expect($menu)->toContain('target="_blank"')
        ->and($menu)->toContain('noopener noreferrer')
        ->and($menu)->toContain('ssoAccountUrl')
        ->and($menu)->toContain("router.post('/auth/logout')")
        ->and($menu)->toContain('href="/profile"');
});
