<?php

declare(strict_types=1);

use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Providers\InboundServiceProvider;
use App\Modules\Inbound\Services\ClamAvScanner;
use App\Modules\Inbound\Services\VentSecScanner;

/*
 | FO2 — az `INBOUND_SCANNER=clamav|ventsec` kapcsoló. Alapértelmezés: clamav. Élesben a
 | ventsec kapcsoló hiányos konfigurációval INDULÁSKOR bukik (mint a többi éles guard).
 */

function completeVentSecConfig(): void
{
    config([
        'ventsec.token_url' => 'https://sso.test/oauth/token', 'ventsec.client_id' => 'ventmail-prod',
        'ventsec.client_secret' => 'titok', 'ventsec.scan_url' => 'https://sec.test',
    ]);
}

it('alapból a ClamAV marad', function () {
    expect(config('inbound.scanner'))->toBe('clamav')
        ->and(app(VirusScanner::class))->toBeInstanceOf(ClamAvScanner::class);
});

it('INBOUND_SCANNER=ventsec a VentSecScannert köti', function () {
    config(['inbound.scanner' => 'ventsec']);
    completeVentSecConfig();

    expect(InboundServiceProvider::scannerDriver())->toBe('ventsec')
        ->and(app(VirusScanner::class))->toBeInstanceOf(VentSecScanner::class);
});

it('ismeretlen érték hiba (elgépelés ne adjon csendben másik szkennert)', function () {
    config(['inbound.scanner' => 'clamv']);

    expect(fn () => InboundServiceProvider::scannerDriver())->toThrow(InvalidArgumentException::class, 'INBOUND_SCANNER');
});

it('élesben/stagingen hiányos ventsec-konfigurációval a boot elbukik', function (string $missing) {
    config(['inbound.scanner' => 'ventsec']);
    completeVentSecConfig();
    config(["ventsec.{$missing}" => '']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => InboundServiceProvider::scannerDriver())
        ->toThrow(RuntimeException::class, 'INBOUND_SCANNER=ventsec');
})->with(['client_id', 'client_secret', 'scan_url']);

it('élesben teljes konfigurációval rendben, és a clamav kapcsolóra nincs guard', function () {
    app()->detectEnvironment(fn () => 'production');

    config(['inbound.scanner' => 'clamav']);
    expect(InboundServiceProvider::scannerDriver())->toBe('clamav');

    config(['inbound.scanner' => 'ventsec']);
    completeVentSecConfig();
    expect(InboundServiceProvider::scannerDriver())->toBe('ventsec');
});

it('fejlesztésben/tesztben a hiányos konfiguráció nem dob (a szkenner fail-closed unavailable)', function () {
    config(['inbound.scanner' => 'ventsec', 'ventsec.client_secret' => '']);

    expect(InboundServiceProvider::scannerDriver())->toBe('ventsec');
});
