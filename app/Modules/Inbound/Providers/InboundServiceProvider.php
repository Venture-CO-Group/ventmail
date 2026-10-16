<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Providers;

use App\Modules\Inbound\Console\PurgeInboundCommand;
use App\Modules\Inbound\Console\ServeMxCommand;
use App\Modules\Inbound\Console\VerifyInboundMxCommand;
use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Contracts\InboundSink;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Services\ClamAvScanner;
use App\Modules\Inbound\Services\InboundIngest;
use App\Modules\Inbound\Services\S3RawMailStore;
use App\Modules\Inbound\Services\VentSecScanner;
use App\Modules\Inbound\Services\VerifiedInboundResolver;
use App\Modules\Support\ModuleServiceProvider;
use InvalidArgumentException;
use RuntimeException;

/**
 * Inbound modul — MX-fogadás, sandboxolt parse, route-motor.
 */
final class InboundServiceProvider extends ModuleServiceProvider
{
    // A `/v1` API-KULCCSAL hitelesít, nem böngésző-session-nel: a kontextus magából a
    // kulcsból származik, tehát az `auth` + `workspace` páros itt nem értelmezett. A saját
    // őrt (`api.auth`) viszont kötelező megadni — teszt kényszeríti ki.
    protected bool $routesPrecedeAuthentication = true;

    protected array $apiMiddleware = ['api.auth'];

    protected string $apiPrefix = 'v1';

    public function register(): void
    {
        // Külső gazdák interfész mögött (fájltár, vírusirtó) — a fake a teszttel fedett
        // kontraktus, és a csere config, nem domain-kód.
        $this->app->bind(RawMailStore::class, S3RawMailStore::class);
        // A szkenner `INBOUND_SCANNER`-ből választ (clamav | ventsec); lásd `scannerDriver()`.
        $this->app->bind(VirusScanner::class, fn ($app) => match (self::scannerDriver()) {
            'ventsec' => $app->make(VentSecScanner::class),
            default => $app->make(ClamAvScanner::class),
        });

        $this->app->bind(InboundRecipientResolver::class, VerifiedInboundResolver::class);
        $this->app->bind(InboundSink::class, InboundIngest::class);
    }

    /**
     * A csatolmány-szkenner kapcsolója — FAIL-CLOSED (mint a többi éles guard): ismeretlen érték
     * hiba (egy elgépelés ne váltson csendben szkennert), élesben/stagingen a hiányos ventSec-
     * konfiguráció boot-hiba (különben minden csatolmány némán `unavailable` lenne).
     */
    public static function scannerDriver(): string
    {
        $driver = (string) config('inbound.scanner');

        if (! in_array($driver, ['clamav', 'ventsec'], true)) {
            throw new InvalidArgumentException("Ismeretlen INBOUND_SCANNER: „{$driver}\" (clamav | ventsec).");
        }

        if ($driver === 'ventsec' && app()->environment('production', 'staging')) {
            $missing = array_keys(array_filter([
                'VENTSEC_CLIENT_ID' => config('ventsec.client_id'),
                'VENTSEC_CLIENT_SECRET' => config('ventsec.client_secret'),
                'VENTSEC_SCAN_URL' => config('ventsec.scan_url'),
            ], fn ($value) => (string) $value === ''));

            if ($missing !== []) {
                throw new RuntimeException('INBOUND_SCANNER=ventsec, de hiányzik: '.implode(', ', $missing).'.');
            }
        }

        return $driver;
    }

    protected function bootModule(): void
    {
        self::scannerDriver();

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeInboundCommand::class, ServeMxCommand::class, VerifyInboundMxCommand::class]);
        }
    }
}
