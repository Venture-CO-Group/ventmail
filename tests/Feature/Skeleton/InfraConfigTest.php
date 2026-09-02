<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
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

it('egyetlen healthcheck sem no-op', function () {
    // A `php -r 'exit(0);'` és a `caddy version` akkor is zöld, ha a service nem szolgál ki —
    // az „7 service healthy" kritérium ilyenkor cáfolhatatlan állítás lenne.
    foreach (composeConfig()['services'] as $name => $service) {
        $test = implode(' ', (array) ($service['healthcheck']['test'] ?? []));

        if ($test === '') {
            continue;
        }

        expect($test)->not->toMatch('/exit\(0\)|(^|\s)(caddy|php)\s+(version|-v)(\s|$)/',
            "no-op healthcheck: {$name}");
    }
});

it('a kimappelt portok csak a localhoston hallgatnak', function () {
    // 0.0.0.0-ra publikált, jelszó nélküli Redis/Postgres idegen hálón támadási felület.
    foreach (composeConfig()['services'] as $name => $service) {
        foreach ((array) ($service['ports'] ?? []) as $mapping) {
            expect((string) $mapping)->toStartWith('127.0.0.1:', "a(z) {$name} nem localhostra publikál: {$mapping}");
        }
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

    foreach (['artisan test', 'pint', 'vue-tsc', 'composer audit', 'npm audit', 'gitleaks', 'npm ci'] as $gate) {
        expect($ci)->toContain($gate);
    }
});

it('az .env.example nem tartalmaz titkot', function () {
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Valódi titkok: ezek SOHA nem kaphatnak értéket az example-ben. A dokumentált,
    // kizárólag lokálisan érvényes dev-értékek (Basic Auth hash, Meili dev key, dev DB-jelszó)
    // szándékosan benne vannak, hogy friss klónon elinduljon a stack.
    $secretKeys = ['APP_KEY', 'OIDC_CLIENT_SECRET', 'REVERB_APP_SECRET', 'DKIM_PRIVATE_KEY'];

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

it('a session-védelem alapból bekapcsolt', function () {
    // Az OIDC state/nonce/PKCE verifier a session-payloadban él, a store Redis.
    expect(config('session.encrypt'))->toBeTrue('a session titkosítása ki van kapcsolva');
    expect(config('session.same_site'))->toBe('lax');
});

it('az .env.example nem hagyja jelszó nélkül a Redist éles használatra', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('REDIS_PASSWORD');
    expect($env)->toContain('ÉLES környezetben a jelszó KÖTELEZŐ');
});

it('a stack FUTTATJA az ütemezőt', function () {
    // A `routes/console.php` ütemezései csak akkor érnek valamit, ha valaki futtatja őket.
    // Enélkül az `o:deliverytime`-ra adott 202 zsákutca (a levél sosem megy ki), és a
    // domain-újraellenőrzés sem fut — vagyis egy deverifikált domain örökre `verified`
    // maradna.
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));

    $scheduler = $compose['services']['scheduler'] ?? null;

    expect($scheduler)->not->toBeNull('nincs scheduler service a compose-ban');
    expect(implode(' ', (array) $scheduler['command']))->toContain('schedule:work');
});

it('minden ütemezett parancs létezik', function () {
    // Egy elgépelt parancsnév csendben soha nem futna le.
    $schedule = app(Schedule::class);

    $missing = [];
    $checked = 0;

    foreach ($schedule->events() as $event) {
        if (preg_match('/artisan[\'\"]? ([a-z0-9:-]+)/i', $event->command ?? '', $match) !== 1) {
            continue;
        }

        $checked++;

        if (! array_key_exists($match[1], Artisan::all())) {
            $missing[] = $match[1];
        }
    }

    // Ha a minta elromlik, a teszt üresen is zöld lenne.
    expect($checked)->toBeGreaterThanOrEqual(5, 'nem sikerült egyetlen ütemezett parancsot sem kiolvasni');
    expect($missing)->toBeEmpty('nem létező ütemezett parancs: '.implode(', ', $missing));
});
