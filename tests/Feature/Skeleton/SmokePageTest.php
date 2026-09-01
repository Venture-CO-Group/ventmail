<?php

declare(strict_types=1);

/*
 | M0-0 elfogadási kritérium #2 — a smoke oldal Inertián keresztül renderel,
 | és @venture/ui komponenst használ @venture/design tokenekkel.
 */

it('a smoke oldal Inertia-választ ad', function () {
    // A backend-kapu nem függhet a lefordított Vite-manifesttől (a CI ott nem buildel);
    // a tényleges renderelést a Playwright e2e fedi.
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Smoke'));
});

it('a smoke oldal Vue komponense @venture/ui-ból importál', function () {
    $source = file_get_contents(resource_path('js/Pages/Smoke.vue'));

    expect($source)->toContain('@venture/ui');
});

it('az app belépési pontja betölti a @venture/design tokeneket', function () {
    $source = file_get_contents(resource_path('js/app.ts'));

    expect($source)->toContain('@venture/design');
});

it('a frontend nem tartalmaz beégetett design tokent', function () {
    $files = collect(File::allFiles(resource_path('js')))
        ->merge(File::exists(resource_path('css')) ? File::allFiles(resource_path('css')) : [])
        ->filter(fn ($file) => in_array($file->getExtension(), ['vue', 'ts', 'css'], true));

    $offenders = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        // CLAUDE.md: hardcode szín / spacing / font TILOS — mindhármat nézzük.
        $hardcoded = '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\('          // szín
            .'|\b\d+(\.\d+)?(px|rem|em)\b'                          // spacing / méret
            .'|font-family\s*:/i';                                    // betűtípus

        if (preg_match($hardcoded, $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBeEmpty(
        'beégetett szín a frontendben (csak @venture/design token használható): '.implode(', ', $offenders)
    );
});
