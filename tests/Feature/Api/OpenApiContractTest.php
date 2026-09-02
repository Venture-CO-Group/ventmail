<?php

declare(strict_types=1);

use App\Modules\Sending\Gate\GateReason;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 | A1 #9–#10 — a `/v1` kontraktus (golden rule 7).
 |
 | Ami egyszer kimegy, az SZERZŐDÉS. Az OpenAPI-leírás nem dokumentáció, hanem a
 | kontraktus egyetlen forrása: ebből generálódik a `@venture/sdk`, és ebből derül ki, ha
 | valaki véletlenül elmozdít egy mezőt. A **drift = 0** ezt jelenti.
 */

function openapi(): array
{
    return Yaml::parseFile(base_path('docs/openapi/ventmail-v1.yaml'));
}

it('az OpenAPI-leírás érvényes és verziózott', function () {
    $spec = openapi();

    expect($spec['openapi'])->toStartWith('3.');
    expect($spec['info']['version'])->toBe('1.0.0');
    expect($spec['servers'][0]['url'])->toContain('/v1');
});

it('minden /v1 route szerepel az OpenAPI-ban', function () {
    $spec = openapi();
    $documented = array_keys($spec['paths']);

    $missing = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'v1/')) {
            continue;
        }

        // Az OpenAPI útvonalak `/`-rel kezdődnek és a `{param}` alakot használják.
        $path = '/'.preg_replace('/\{(\w+)\??\}/', '{$1}', substr($route->uri(), 3));

        if (! in_array($path, $documented, true)) {
            $missing[] = $route->methods()[0].' '.$path;
        }
    }

    expect($missing)->toBeEmpty('dokumentálatlan /v1 endpoint: '.implode(', ', $missing));
});

it('az OpenAPI nem ír le nem létező endpointot', function () {
    $spec = openapi();

    $live = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'v1/')) {
            $live[] = '/'.substr($route->uri(), 3);
        }
    }

    $ghosts = array_diff(array_keys($spec['paths']), $live);

    expect($ghosts)->toBeEmpty('az OpenAPI nem létező endpointot ír le: '.implode(', ', $ghosts));
});

it('minden gate-elutasítási kód dokumentált', function () {
    $spec = openapi();
    $documented = $spec['components']['schemas']['ErrorCode']['enum'] ?? [];

    foreach (GateReason::cases() as $reason) {
        // A `toContain` a további argumentumokat KERESETT értékként érti, nem üzenetként.
        expect(in_array($reason->value, $documented, true))
            ->toBeTrue("nem dokumentált hibakód: {$reason->value}");
    }
});

it('a hibaformátum egységes: kód + üzenet', function () {
    $spec = openapi();
    $error = $spec['components']['schemas']['Error']['properties']['error']['properties'] ?? [];

    expect(array_keys($error))->toContain('code')->toContain('message');
});

it('az Idempotency-Key kötelezősége dokumentált a küldő endpointokon', function () {
    $spec = openapi();

    // A paraméterek `$ref`-fel hivatkoznak a közös definícióra — fel kell oldani.
    $resolve = function (array $parameter) use ($spec): array {
        if (! isset($parameter['$ref'])) {
            return $parameter;
        }

        $path = explode('/', ltrim((string) $parameter['$ref'], '#/'));

        return data_get($spec, implode('.', $path), []);
    };

    foreach (['/messages', '/messages.mime'] as $path) {
        $params = array_map($resolve, $spec['paths'][$path]['post']['parameters'] ?? []);
        $key = collect($params)->firstWhere('name', 'Idempotency-Key');

        expect($key)->not->toBeNull("hiányzik az Idempotency-Key: {$path}");
        expect($key['required'])->toBeTrue("az Idempotency-Key nem kötelezőként szerepel: {$path}");
    }
});

/*
 | MEZŐ-SZINTŰ drift-ellenőrzés a `@venture/sdk` felé (A1 #9: drift = 0).
 |
 | Az útvonal-egyezés nem elég: a korábbi SDK-ban nem volt `from` (a valóságban kötelező),
 | a `subject` kötelezőként szerepelt (a valóságban opcionális), és a `status` uniója négy
 | olyan értéket tartalmazott, amit a szerződés nem ad. Egy SDK-ra írt kliens így vagy 400-at
 | kapott, vagy típushibára futott.
 */

/**
 * A `@venture/sdk` forrása. Két helyen keressük: a szomszédos repóban (host, CI-ban
 * symlinkelve) és a konténerbe mountolt útvonalon. Ha egyik sem elérhető, a teszt
 * HANGOSAN kihagyódik — nem csendben zöldül.
 */
function sdkMailSource(): string
{
    foreach ([base_path('../venture-sdk/src/services/mail.ts'), '/venture-packages/venture-sdk/src/services/mail.ts'] as $path) {
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

function requireSdk(): string
{
    $source = sdkMailSource();

    if ($source === '') {
        test()->markTestSkipped(
            'A @venture/sdk nem elérhető ebben a környezetben — a drift-ellenőrzés a CI-ban és '
            .'a host-on fut (ott a sibling repo be van kötve).'
        );
    }

    return $source;
}

it('az SDK ismeri a kötelező kérés-mezőket', function () {
    $sdk = requireSdk();

    // A `from` KÖTELEZŐ (nincs `?`), a `subject` opcionális.
    expect($sdk)->toMatch('/\n\s+from: string;/');
    expect($sdk)->toMatch('/\n\s+subject\?: string;/');
    expect($sdk)->toContain('idempotencyKey: string');
});

it('az SDK állapot-uniója egyezik a szerződéssel', function () {
    $spec = openapi();
    $documented = $spec['components']['schemas']['SendAccepted']['properties']['status']['enum'];

    $sdk = sdkMailSource();

    // A `toContain` a további argumentumokat KERESETT értékként érti, nem üzenetként.
    foreach ($documented as $status) {
        expect(str_contains($sdk, "\"{$status}\""))
            ->toBeTrue("az SDK nem ismeri a(z) {$status} állapotot");
    }

    // És nem ismer olyat, amit a szerződés nem ad.
    foreach (['queued', 'bounced'] as $ghost) {
        expect(str_contains($sdk, "| \"{$ghost}\""))
            ->toBeFalse("az SDK nem létező állapotot hirdet: {$ghost}");
    }
});

it('az SDK hibakódjai a szerződésből származnak', function () {
    $spec = openapi();
    $documented = $spec['components']['schemas']['ErrorCode']['enum'];

    $sdk = requireSdk();

    preg_match_all('/^\s+\| "([A-Z_]+)"$/m', $sdk, $matches);

    $ghosts = array_diff($matches[1] ?? [], $documented);

    expect($ghosts)->toBeEmpty('az SDK nem létező hibakódot hirdet: '.implode(', ', $ghosts));
});
