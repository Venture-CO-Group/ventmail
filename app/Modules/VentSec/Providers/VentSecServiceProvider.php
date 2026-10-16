<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Providers;

use App\Modules\Support\ModuleServiceProvider;
use App\Modules\VentSec\Console\HeartbeatCommand;
use App\Modules\VentSec\Contracts\VentSecEvents;
use App\Modules\VentSec\Services\HttpVentSecEvents;
use RuntimeException;

/**
 * VentSec modul — m2m token, ítélet-ellenőrzés, VSES események (FO2).
 */
final class VentSecServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VentSecEvents::class, HttpVentSecEvents::class);
    }

    protected function bootModule(): void
    {
        self::assertEventsConfigured();

        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class]);
        }
    }

    /**
     * FAIL-CLOSED boot-guard (mint a többi éles guard): élesben/stagingen a bekapcsolt
     * eseménykibocsátás hiányos konfigurációval nem indul — különben a szívverés némán
     * elmaradna, és a ventSec „csendes forrást" látna.
     */
    public static function assertEventsConfigured(): void
    {
        if (! config('ventsec.events.enabled') || ! app()->environment('production', 'staging')) {
            return;
        }

        $missing = array_keys(array_filter([
            'VENTSEC_INGEST_URL' => config('ventsec.events.url'),
            'VENTSEC_INGEST_SECRET' => config('ventsec.events.source_secret'),
            'VENTSEC_CLIENT_ID' => config('ventsec.client_id'),
            'VENTSEC_CLIENT_SECRET' => config('ventsec.client_secret'),
        ], fn ($value) => (string) $value === ''));

        if ($missing !== []) {
            throw new RuntimeException('VENTSEC_EVENTS_ENABLED=true, de hiányzik: '.implode(', ', $missing).'.');
        }
    }
}
