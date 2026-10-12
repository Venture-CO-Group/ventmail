<?php

declare(strict_types=1);

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
    $limits = ['app' => '2g', 'worker' => '2g', 'scheduler' => '512m', 'web' => '256m', 'redis' => '1280m'];

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
        $php = in_array($name, ['app', 'worker', 'scheduler'], true);

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

it('a Redis a színeken kívül él, és a kiadások nem osztanak írható tárolót', function () {
    expect(vmDataCompose()['name'])->toBe('ventmail-data')
        ->and(vmProdCompose()['services'])->not->toHaveKey('redis')
        ->and(array_keys(vmProdCompose()['volumes'] ?? []))->toBe(['fpm']);
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
        ->and($site)->toMatch('/^ventmail\.app \{\n\timport security_headers\n\tredir https:\/\/app\.ventmail\.app\{uri\} 301/m')
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
