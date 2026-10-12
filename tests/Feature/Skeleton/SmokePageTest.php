<?php

declare(strict_types=1);

/*
 | M0-0 elfogadási kritérium #2 — a smoke oldal Inertián keresztül renderel,
 | és @venture/ui komponenst használ @venture/design tokenekkel.
 */

it('a belépő oldal Inertián keresztül renderel', function () {
    // A backend-kapu nem függhet a lefordított Vite-manifesttől (a CI ott nem buildel);
    // a tényleges renderelést a Playwright e2e fedi.
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

it('a belépő a ventSSO-val azonos üveg-kártya + glow-keret nyelvet használja', function () {
    // A @venture/ui-ban nincs link-gomb (a VtButton csak <button>): a belépés teljes
    // navigáció, ezért <a> + design-utility (`vt-glass`) és token-stílus.
    $shell = file_get_contents(resource_path('js/Components/AuthShell.vue'));
    $login = file_get_contents(resource_path('js/Pages/Auth/Login.vue'));

    expect($shell)->toContain('vt-glass')->toContain('vt-glow-rim')
        ->and($login)->toContain('href="/auth/redirect"')
        ->and($login)->not->toContain('<Link');
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

        /*
         | CLAUDE.md: hardcode szín / spacing / font TILOS — mindhármat nézzük.
         |
         | A `font-family` viszont csak akkor tilos, ha NEM tokenre mutat. A szabály célja
         | az, hogy ne legyen beégetett betűkészlet-lista; a `font-family: var(--vt-font-…)`
         | épp a helyes használat — az app-héjnak muszáj kimondania, melyik token-családot
         | kéri, különben a böngésző alapbetűjével jelenne meg a felület.
         */
        $hardcoded = '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\('          // szín
            .'|\b\d+(\.\d+)?(px|rem|em)\b'                          // spacing / méret
            // A lookahead a KETTŐSPONT UTÁN áll, és maga nyeli a szóközt: külön `\s*`-gal
            // a motor visszalépne nulla szóközre, és a lookahead a szóközre nézve mindig
            // igazat adna — a szabály némán megszűnne (mérve).
            .'|font-family\s*:(?!\s*var\(--vt-)/i';                    // betűtípus token nélkül

        if (preg_match($hardcoded, $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBeEmpty(
        'beégetett szín a frontendben (csak @venture/design token használható): '.implode(', ', $offenders)
    );
});
