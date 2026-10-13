<?php

declare(strict_types=1);

use Laravel\Horizon\ProvisioningPlan;
use Symfony\Component\Yaml\Yaml;

/*
 | Éles invariánsok a deploy-fájlokban (deploy-ovh). Ugyanaz a szerződés, mint a
 | ventSSO-nál, plusz a ventMail sajátjai: két Postal-példány a belső hálózaton, a
 | webhook-útvonal az edge-en elzárva, és egyetlen szolgáltatás sem publikál portot.
 */

function vmProdCompose(): array
{
    return Yaml::parseFile(base_path('deploy/compose.prod.yml'));
}

function vmDataCompose(): array
{
    return Yaml::parseFile(base_path('deploy/compose.data.yml'));
}

/** @return array<string, array<string, mixed>> */
function vmLongRunning(): array
{
    return array_filter(
        vmProdCompose()['services'] + vmDataCompose()['services'],
        fn (array $service) => ! in_array('ops', $service['profiles'] ?? [], true),
    );
}

/** @return list<string> */
function vmNetworks(array $service): array
{
    $networks = $service['networks'] ?? ['default'];

    return array_is_list($networks) ? $networks : array_keys($networks);
}

it('egyetlen szolgáltatás sem publikál host-portot (Postgres és Redis sem)', function () {
    foreach (vmProdCompose()['services'] + vmDataCompose()['services'] as $name => $service) {
        expect($service)->not->toHaveKey('ports', "{$name} publishes a host port");
    }
});

it('csak pinelt registry-image fut, soha :latest és soha helyi build', function () {
    foreach (vmProdCompose()['services'] + vmDataCompose()['services'] as $name => $service) {
        expect($service)->not->toHaveKey('build', "{$name} builds locally")
            ->and($service['image'])->not->toEndWith(':latest');

        if (str_starts_with($service['image'], 'ghcr.io/')) {
            expect($service['image'])->toBe('ghcr.io/venture-co-group/ventmail:${VENTMAIL_TAG:?VENTMAIL_TAG is set by deploy.sh}');
        } else {
            expect($service['image'])->toMatch('/:\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', "{$name} image is not version-pinned");
        }
    }
});

it('minden hosszan futó szolgáltatásnak van memória- és CPU-limitje és restart-politikája', function () {
    $limits = ['app' => '2g', 'worker' => '2g', 'worker-imports' => '3g', 'scheduler' => '512m', 'web' => '256m', 'redis' => '1280m'];

    foreach ($limits as $name => $limit) {
        $service = vmLongRunning()[$name] ?? null;

        expect($service)->not->toBeNull("missing service {$name}")
            ->and($service['mem_limit'])->toBe($limit)
            ->and($service)->toHaveKey('cpus')
            ->and($service['restart'])->toBe('unless-stopped');
    }
});

it('a Postgrest csak a mail-db-n, a Postalt csak a postal-api-n éri el; az edge-en csak a web van', function () {
    $networks = vmProdCompose()['networks'];

    expect($networks['mail-db'])->toBe(['name' => 'mail-db', 'external' => true])
        ->and($networks['postal-api'])->toBe(['name' => 'postal-api', 'external' => true])
        ->and($networks['edge'])->toBe(['name' => 'edge', 'external' => true])
        ->and($networks['data'])->toBe(['name' => 'ventmail-data', 'external' => true]);

    foreach (vmLongRunning() as $name => $service) {
        $joined = vmNetworks($service);
        $php = in_array($name, ['app', 'worker', 'worker-imports', 'scheduler'], true);

        expect(in_array('edge', $joined, true))->toBe($name === 'web', "{$name} edge membership")
            ->and(in_array('mail-db', $joined, true))->toBe($php, "{$name} mail-db membership")
            ->and(in_array('postal-api', $joined, true))->toBe($php || $name === 'web', "{$name} postal-api membership");
    }
});

it('blue/green: a színek a saját web-upstreamjüket hirdetik, a Postal egy stabil nevet lát', function () {
    $web = vmProdCompose()['services']['web']['networks'];

    expect($web['edge']['aliases'])->toBe(['ventmail-web-${VENTMAIL_COLOR:?VENTMAIL_COLOR is set by deploy.sh}'])
        ->and($web['postal-api']['aliases'])->toBe(['ventmail-web']);
});

it('a Redis a színeken kívül él; a színek csak a két külső, nevesített storage-volume-ot osztják (D227)', function () {
    expect(vmDataCompose()['name'])->toBe('ventmail-data')
        ->and(vmProdCompose()['services'])->not->toHaveKey('redis')
        ->and(vmProdCompose()['volumes'])->toBe([
            'fpm' => [],
            'storage' => ['name' => 'ventmail-storage', 'external' => true],
            'storage-public' => ['name' => 'ventmail-storage-public', 'external' => true],
        ]);
});

it('a megosztott storage: minden PHP-szerep és az ops ugyanazt a storage/app-ot látja, a web csak a public részt, csak olvasva (D227)', function () {
    $services = vmProdCompose()['services'];
    $shared = ['storage:/app/storage/app', 'storage-public:/app/storage/app/public'];

    foreach (['app', 'worker', 'worker-imports', 'scheduler', 'ops'] as $name) {
        foreach ($shared as $mount) {
            expect(in_array($mount, $services[$name]['volumes'] ?? [], true))->toBeTrue("{$name} misses {$mount}");
        }
    }

    // A web CSAK a nyilvános fájlokat látja (import, riport, logó nem), és nem írhat.
    $web = $services['web']['volumes'];
    expect($web)->toContain('storage-public:/app/storage/app/public:ro')
        ->and(collect($web)->filter(fn (string $m) => str_starts_with($m, 'storage:'))->all())->toBe([]);

    // A Caddy a /storage/* útvonalat CSAK fájlként szolgálja ki (PHP nélkül) a csatolt public részből.
    $caddy = (string) file_get_contents(base_path('docker/caddy/Caddyfile.web'));
    expect($caddy)->toMatch('/handle_path \/storage\/\* \{\s*root \* \/app\/storage\/app\/public\s/')
        ->and($caddy)->toContain('Cross-Origin-Resource-Policy "cross-origin"');

    // Az entrypoint induláskor ellenőrzi, hogy a csatolt tároló írható (rossz jogosultság = látható hiba).
    expect((string) file_get_contents(base_path('docker/entrypoint.sh')))->toContain('ensure_storage');
});

it('a FastCGI csak színenkénti unix socketen megy', function () {
    $www = file_get_contents(base_path('docker/php/www.conf'));
    $caddy = file_get_contents(base_path('docker/caddy/Caddyfile.web'));

    expect($www)->toMatch('/^listen = \/run\/php\/fpm\.sock$/m')
        ->and($www)->toMatch('/^listen\.mode = 0600$/m')
        ->and($caddy)->toContain('php_fastcgi unix//run/php/fpm.sock');
});

it('a web-réteg csak az edge-proxynak hisz', function () {
    expect(file_get_contents(base_path('docker/caddy/Caddyfile.web')))->toContain('trusted_proxies static {$TRUSTED_PROXIES}')
        ->and(file_get_contents(base_path('docker/entrypoint.sh')))->toContain(': "${TRUSTED_PROXIES:?')
        ->and(array_keys(vmProdCompose()['services']['web']['environment']))->toBe(['APP_URL', 'TRUSTED_PROXIES'])
        ->and(vmProdCompose()['services']['web'])->not->toHaveKey('env_file');
});

it('a Redis jelszóval védett, csak a saját hálózatán, és csak a jelszót kapja', function () {
    $redis = vmDataCompose()['services']['redis'];

    expect(implode(' ', (array) $redis['command']))->toContain('requirepass')
        ->and(implode(' ', (array) $redis['command']))->toContain('noeviction')
        ->and(vmNetworks($redis))->toBe(['data'])
        ->and(array_keys($redis['environment']))->toBe(['REDIS_PASSWORD']);
});

it('az edge-site csak egészséges színre küld, és a webhook-útvonalat elzárja', function () {
    $site = file_get_contents(base_path('deploy/ventmail.caddy'));

    expect($site)->toMatch('/^app\.ventmail\.app \{/m')
        ->and($site)->toMatch('/^api\.ventmail\.app \{/m')
        ->and($site)->toMatch('/^ventmail\.app \{\n\timport ventmail_site ventmail\.app\n\}/m')
        ->and($site)->toMatch('/^www\.ventmail\.app \{\n\timport security_headers\n\tredir https:\/\/ventmail\.app\{uri\} 308\n\}/m')
        ->and($site)->not->toContain('redir https://app.ventmail.app')
        ->and($site)->toContain('reverse_proxy ventmail-web-blue:8080 ventmail-web-green:8080')
        ->and($site)->toContain('health_uri /up')
        ->and($site)->toContain('@internal path /internal /internal/*')
        ->and($site)->toContain('respond @internal 404')
        ->and($site)->toContain('header_up -X-Forwarded-Prefix')
        ->and($site)->toContain('header_up -X-Forwarded-Port');
});

it('nem-root image, healthcheckkel, induláskor migráció nélkül', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($dockerfile)->toMatch('/^HEALTHCHECK /m')
        ->and($dockerfile)->toMatch('/^USER 10001(:10001)?$/m')
        ->and($dockerfile)->toContain('composer install --no-dev')
        ->and($dockerfile)->toMatch('/ARG PHP_IMAGE=php:8\.3\.\d+-fpm-alpine/')
        ->and($dockerfile)->not->toMatch('/FROM [^\s]+:latest/')
        ->and($entrypoint)->not->toContain('migrate')
        ->and($entrypoint)->not->toContain('|| true');
});

it('az image-be nem kerül env-fájl, kulcs vagy teszt', function () {
    $ignored = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES);

    expect($ignored)->toContain('.env', '.env.*', 'storage/*.key', 'tests', 'deploy', 'node_modules');
});

it('a production env-példa csak kulcsokat tartalmaz, értéket soha', function () {
    $lines = array_filter(file(base_path('deploy/.env.production.example'), FILE_IGNORE_NEW_LINES),
        fn (string $line) => $line !== '' && ! str_starts_with($line, '#'));

    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        expect($line)->toMatch('/^[A-Z][A-Z0-9_]*=$/', "has a value: {$line}");
    }
});

it('a release workflow minden actionje commit SHA-ra pinelt (packages: write)', function () {
    $workflow = file_get_contents(base_path('.github/workflows/release.yml'));

    preg_match_all('/^\s*(?:-\s*)?uses:\s*(\S+)/m', $workflow, $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $action) {
        if (str_starts_with($action, './')) {
            continue;
        }
        expect($action)->toMatch('/@[0-9a-f]{40}$/', "{$action} is not pinned to a commit");
    }
});

it('a letöltött build-eszközt checksum ellenőrzi', function () {
    preg_match_all('/^ADD .*$/m', file_get_contents(base_path('Dockerfile')), $adds);

    foreach ($adds[0] as $add) {
        if (str_contains($add, 'http')) {
            expect($add)->toMatch('/--checksum=sha256:[0-9a-f]{64}/');
        }
    }
});

it('a futó verzió a git tagből jut el a lábléc `config(app.version)` értékéig', function () {
    /*
     | v1.1 · Shell: az avatar-menü alján a verzió látszik. A lánc négy láncszeme: a release
     | workflow a TAGből képzi a `VERSION` build-arg-ot → a Dockerfile `ENV VENTMAIL_VERSION`
     | → `config('app.version')` → az Inertia megosztott `app.version` prop. Ha bármelyik
     | elszakad, a felület „dev"-et mutat éles image-en is.
     */
    $release = file_get_contents(base_path('.github/workflows/release.yml'));
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($release)->toContain('version="${GITHUB_REF_NAME}"')
        ->and($release)->toContain('VERSION=${{ steps.meta.outputs.version }}')
        ->and($dockerfile)->toMatch('/^ARG VERSION=/m')
        ->and($dockerfile)->toContain('VENTMAIL_VERSION=${VERSION}')
        ->and(file_get_contents(base_path('config/app.php')))->toContain("env('VENTMAIL_VERSION'")
        ->and(file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php')))
        ->toContain("'version' => (string) config('app.version')");
});

it('az import külön worker-konténerben fut (saját limit), és az entrypoint/healthcheck ismeri a szerepet', function () {
    $service = vmLongRunning()['worker-imports'];

    expect($service['command'])->toBe(['worker-imports'])
        ->and(vmLongRunning()['worker']['command'])->toBe(['worker']);

    $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
    $health = (string) file_get_contents(base_path('docker/healthcheck.sh'));

    expect($entrypoint)->toContain('worker-imports)')->toContain('horizon --environment=imports')
        ->and($health)->toContain('worker-imports)');

    // A TÉNYLEGESEN INDULÓ supervisorok (review B5): a Horizon a `defaults`-ot MINDEN környezetbe
    // beolvasztja (`array_replace_recursive`), ezért nem a config kulcsait, hanem a
    // `ProvisioningPlan` kimenetét nézzük. A küldő Horizon (production) NEM indít import
    // supervisort, az import-konténer (`imports`) pedig küldő / háttér / kampány-indító supervisort.
    $plan = (new ProvisioningPlan('teszt', config('horizon.environments'), config('horizon.defaults')))->toSupervisorOptions();
    $started = fn (string $env): array => collect($plan[$env])->keys()->sort()->values()->all();

    expect($started('production'))->toBe(['background', 'campaign-start', 'reports', 'sending'])
        ->and($started('imports'))->toBe(['imports', 'imports-inspect'])
        // A fejlesztői és a teszt-környezet egyetlen Horizonon mind a hat supervisort futtatja.
        ->and($started('local'))->toBe(['background', 'campaign-start', 'imports', 'imports-inspect', 'reports', 'sending'])
        ->and($started('testing'))->toBe(['background', 'campaign-start', 'imports', 'imports-inspect', 'reports', 'sending']);

    // A felülírt értékek a ténylegesen induló supervisoron is érvényesek (nem csak a configban).
    expect($plan['production']['sending']->maxProcesses)->toBe(20)
        ->and($plan['production']['sending']->timeout)->toBe(120)
        ->and($plan['imports']['imports']->timeout)->toBe(3600)
        ->and($plan['imports']['imports']->connection)->toBe('redis_imports')
        ->and($plan['imports']['imports-inspect']->timeout)->toBe(60)
        ->and($plan['production']['campaign-start']->timeout)->toBe(900)
        // v1.1 · 8. modul: a PDF-riport saját supervisora; a kapcsolat retry_after-e a timeout fölött.
        ->and($plan['production']['reports']->timeout)->toBe(120)
        ->and($plan['production']['reports']->maxProcesses)->toBe(1)
        ->and($plan['production']['reports']->connection)->toBe('redis_reports')
        ->and(config('queue.connections.redis_reports.retry_after'))->toBeGreaterThan(120);
});

it('a php.ini kivétel-trace-e nem tartalmaz függvény-argumentumot', function () {
    expect(parse_ini_file(base_path('docker/php/php.ini'))['zend.exception_ignore_args'])->toBe('1');
});

it('a gd és a zip telepítve a Dockerfile-ban', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toMatch('/install-php-extensions[^\n]* gd\b/')->toMatch('/install-php-extensions[^\n]* zip\b/');
});

it('az image ellenőrzi, hogy a gyerekfolyamat címtér-limitje (ulimit -v) ELFOGADOTT és ÉRVÉNYESÜL (D222, D260)', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toMatch("/RUN sh -c 'ulimit -v \\d+ && exec php /")
        // A negált próba: egy 200 MB-os foglalás 128 MB címtérben el KELL bukjon.
        ->and($dockerfile)->toMatch("/&& ! sh -c 'ulimit -v 131072 && exec php [^\\n]*200<<20/");

    // A worker-imports (3g) legrosszabb esete (D259): 4 worker × 256 MB memory_limit + master és 2 supervisor
    // (~150 MB) + 4 gyerek × címtér-limit — 3 GiB alatt.
    expect(4 * 256 + 150 + 4 * (int) config('audience.import.xlsx_child_address_space_mb'))->toBeLessThan(3 * 1024);
});

it('a Gotenberg-sidecar tag+digest szerint pinelt, belső hálón, kimenő forgalom és host-port nélkül (D238)', function () {
    $render = Yaml::parseFile(base_path('deploy/compose.render.yml'));
    $gotenberg = $render['services']['gotenberg'];
    $command = implode(' ', (array) $gotenberg['command']);

    expect($render['name'])->toBe('ventmail-render')
        ->and($gotenberg['image'])->toMatch('/^gotenberg\/gotenberg:8\.\d+\.\d+-chromium@sha256:[0-9a-f]{64}$/')
        ->and($gotenberg)->not->toHaveKey('ports')
        ->and($gotenberg)->not->toHaveKey('build')
        ->and($render['networks']['render'])->toBe(['name' => 'ventmail-render', 'internal' => true])
        ->and(vmNetworks($gotenberg))->toBe(['render'])
        ->and($gotenberg['read_only'])->toBeTrue()
        ->and($gotenberg['cap_drop'])->toBe(['ALL'])
        ->and($gotenberg['security_opt'])->toContain('no-new-privileges:true')
        ->and($gotenberg['mem_limit'])->toBe('1g')
        ->and($gotenberg['restart'])->toBe('unless-stopped')
        ->and($command)->toContain('--chromium-allow-list=^file:///tmp/.*')
        ->toContain('--api-disable-download-from=true')
        ->toContain('--webhook-disable=true')
        ->toContain('--chromium-clear-storage=true')
        ->toContain('--api-body-limit=10MB');

    // CSAK az app és a worker éri el (a riport-job a worker `reports` során fut, a letöltés az appon).
    foreach (vmProdCompose()['services'] as $name => $service) {
        expect(in_array('render', vmNetworks($service), true))->toBe(in_array($name, ['app', 'worker'], true), "{$name} render membership");
    }

    expect(vmProdCompose()['networks']['render'])->toBe(['name' => 'ventmail-render', 'external' => true]);

    // A CI kontraktus-jobja UGYANAZT az image-et futtatja.
    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('image: '.$gotenberg['image']);
});

it('az image buildje bukik a PDF-riport csomagja nélkül, a production-env a Gotenberget kéri', function () {
    expect((string) file_get_contents(base_path('Dockerfile')))->toContain('test -f public/build/report/report.js')
        ->and((string) file_get_contents(base_path('package.json')))->toContain('vite build --config vite.report.config.ts')
        ->and((string) file_get_contents(base_path('deploy/init-env.sh')))->toContain('ANALYTICS_PDF_RENDERER=gotenberg');
});
