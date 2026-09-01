<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 | M0-0 elfogadási kritérium #1 és #3 — a compose-fájl és a CI szerkezeti garanciái.
 | (A tényleges „docker compose up -d → healthy" futtatás a packet manuális/CI kapuja;
 |  itt azt kényszerítjük ki, hogy a definíció maga helyes és ne csússzon el.)
 */

function composeConfig(): array
{
    return Yaml::parseFile(base_path('docker-compose.yml'));
}

it('a compose mind a 7 service-t definiálja', function () {
    $services = array_keys(composeConfig()['services'] ?? []);

    foreach (['caddy', 'app', 'db', 'redis', 'horizon', 'reverb', 'meilisearch'] as $service) {
        expect($services)->toContain($service);
    }
});

it('az adatbázis TimescaleDB pg17 image-et használ dev-ben', function () {
    expect(composeConfig()['services']['db']['image'] ?? '')
        ->toContain('timescaledb')
        ->toContain('pg17');
});

it('minden service-nek van healthcheckje vagy függ egy healthy service-től', function () {
    $services = composeConfig()['services'];

    foreach ($services as $name => $service) {
        $hasHealthcheck = isset($service['healthcheck']);
        $waitsForHealthy = collect($service['depends_on'] ?? [])
            ->contains(fn ($dep) => ($dep['condition'] ?? null) === 'service_healthy');

        expect($hasHealthcheck || $waitsForHealthy)
            ->toBeTrue("a(z) {$name} service se healthcheckkel, se healthy-függéssel nem rendelkezik");
    }
});

it('a compose named volume-okat használ a perzisztens adatra', function () {
    expect(array_keys(composeConfig()['volumes'] ?? []))
        ->toContain('pgdata')
        ->toContain('redisdata')
        ->toContain('meilidata');
});

it('a CI ugyanazt a Timescale image-et használja, mint a dev', function () {
    $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)->toContain('timescale/timescaledb-ha:pg17');
});

it('a CI minden blokkoló kaput futtat', function () {
    $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

    foreach (['artisan test', 'pint', 'vue-tsc', 'composer audit', 'npm audit', 'gitleaks'] as $gate) {
        expect($ci)->toContain($gate);
    }
});

it('az .env.example nem tartalmaz titkot', function () {
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    $secretKeys = ['APP_KEY', 'OIDC_CLIENT_SECRET', 'MEILISEARCH_KEY', 'DKIM_PRIVATE_KEY'];

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        if (in_array(trim($key), $secretKeys, true)) {
            expect(trim($value))->toBe('', "az .env.example titkot szivárogtat: {$key}");
        }
    }
});

it('az .env.example a kanonikus Venture stacket írja le', function () {
    $env = file_get_contents(base_path('.env.example'));

    foreach (['DB_CONNECTION=pgsql', 'QUEUE_CONNECTION=redis', 'SCOUT_DRIVER=meilisearch',
        'BROADCAST_CONNECTION=reverb', 'SENDING_DRIVER=fake'] as $setting) {
        expect($env)->toContain($setting);
    }
});
