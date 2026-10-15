<?php

declare(strict_types=1);
use App\Support\FontPreload;

/*
 | A betűk a saját originről jönnek (@venture/design fonts.css) — a Google Fonts CDN minden
 | látogató IP-jét a hozzájárulás ELŐTT küldte a Google-nek (GDPR). Nincs DB-igény.
 */

const GOOGLE_FONT_HOSTS = '/fonts\.googleapis\.com|fonts\.gstatic\.com|googleapis|gstatic/i';

it('a landing, a belépő és a hosztolt oldalak HTML-je nem hivatkozik Google-betűre', function () {
    $pages = [
        'landing' => $this->get('http://ventmail.app/')->assertOk()->getContent(),
        'app (belépő)' => $this->withoutVite()->get('http://app.ventmail.app/')->assertOk()->getContent(),
        'hosztolt (űrlap/megerősítés/preference center közös váza)' => view('consent.confirmed')->render(),
        'hosztolt (űrlap köszönő)' => view('audience.form-submitted')->render(),
    ];

    foreach ($pages as $name => $html) {
        expect($html)->not->toMatch(GOOGLE_FONT_HOSTS, "{$name} még Google-betűre hivatkozik");
    }
});

it('a landing és az app pontosan a két fő vágást preloadolja, a manifeszt hash-elt URL-jével, crossorigin-nel', function () {
    // A kulcs a feloldott `file:` út (környezetenként más): a keresés a kulcs utolsó szakaszára megy.
    $manifest = tempnam(sys_get_temp_dir(), 'vite');
    file_put_contents($manifest, json_encode([
        '../../venture-design/dist/fonts/inter-latin.woff2' => ['file' => 'assets/inter-latin-AAA.woff2'],
        '../../venture-design/dist/fonts/inter-latin-ext.woff2' => ['file' => 'assets/inter-latin-ext-BBB.woff2'],
        'node_modules/@venture/design/dist/fonts/space-grotesk-latin.woff2' => ['file' => 'assets/space-grotesk-latin-CCC.woff2'],
        '../../venture-design/dist/fonts/jetbrains-mono-latin.woff2' => ['file' => 'assets/jetbrains-mono-latin-DDD.woff2'],
    ]));
    config(['vite.manifest_path' => $manifest]);

    foreach ([$this->get('http://ventmail.app/')->getContent(), $this->withoutVite()->get('http://app.ventmail.app/')->getContent()] as $html) {
        preg_match_all('#<link rel="preload" as="font" type="font/woff2" href="([^"]+)" crossorigin>#', $html, $m);
        expect($m[1])->toHaveCount(2)
            ->and($m[1][0])->toEndWith('/build/assets/inter-latin-AAA.woff2')
            ->and($m[1][1])->toEndWith('/build/assets/space-grotesk-latin-CCC.woff2');
    }
    unlink($manifest);
});

it('manifeszt nélkül nincs preload (és nincs hiba)', function () {
    config(['vite.manifest_path' => '/nonexistent/manifest.json']);

    expect($this->get('http://ventmail.app/')->assertOk()->getContent())->not->toContain('rel="preload" as="font"');
});

it('a CSP nem engedi a Google-domaineket, a style- és font-src csak saját origin', function () {
    $caddy = (string) file_get_contents(base_path('docker/caddy/snippets/security-headers.caddy'));

    expect($caddy)->not->toMatch(GOOGLE_FONT_HOSTS)
        ->and($caddy)->toContain("font-src 'self';")
        ->and($caddy)->toMatch("/style-src 'self' 'unsafe-inline';/");
});

it('a resources/ és a docker/ alatt semmi forrás nem hivatkozik Google-betűre', function () {
    $offenders = [];
    foreach (['resources', 'docker'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && ! preg_match('/\.(png|jpe?g|webp|woff2?|ico|svg|pdf)$/i', $file->getFilename())
                && preg_match(GOOGLE_FONT_HOSTS, (string) file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('a három betűcsalád a saját originről jön: a belépők importálják a fonts.css-t', function () {
    foreach (['resources/js/app.ts', 'resources/css/landing.css', 'resources/css/hosted.css'] as $entry) {
        expect((string) file_get_contents(base_path($entry)))->toContain('@venture/design/fonts.css');
    }
});

it('az OFL-licencszövegek a public/fonts alól szolgálódnak, és bájtra egyeznek a csomagéval', function () {
    $package = base_path('node_modules/@venture/design/dist/fonts');

    foreach (['inter', 'space-grotesk', 'jetbrains-mono'] as $family) {
        $served = public_path("fonts/LICENSE-{$family}.txt");
        expect(is_file($served))->toBeTrue("hiányzik: public/fonts/LICENSE-{$family}.txt")
            ->and((string) file_get_contents($served))->toContain('SIL OPEN FONT LICENSE');

        // A design-bump nem csúszhat el csendben: ha a csomag települ, a másolatnak azonosnak kell lennie.
        if (is_file("{$package}/LICENSE-{$family}.txt")) {
            expect(hash_file('sha256', $served))->toBe(hash_file('sha256', "{$package}/LICENSE-{$family}.txt"));
        }
    }
});

it('a preload dev-szerver (hot file) mellett kimarad, és a manifesztet processzenként egyszer olvassa', function () {
    $hot = public_path('hot');
    file_put_contents($hot, 'http://localhost:5173');
    try {
        $manifest = tempnam(sys_get_temp_dir(), 'vite');
        file_put_contents($manifest, json_encode(['x/fonts/inter-latin.woff2' => ['file' => 'assets/i.woff2']]));
        config(['vite.manifest_path' => $manifest]);

        expect(FontPreload::urls())->toBe([]);

        unlink($hot);
        expect(FontPreload::urls())->toHaveCount(1);
        unlink($manifest); // memoizált: a második hívás már nem olvas fájlt
        expect(FontPreload::urls())->toHaveCount(1);
    } finally {
        @unlink($hot);
    }
});
